<?php
/**
 * Sitevorx Pro — S3 client tối giản (AWS Signature V4, không phụ thuộc SDK).
 *
 * Tự ký request theo chuẩn AWS SigV4 và gửi qua cURL. Hỗ trợ mọi endpoint
 * S3-compatible (AWS S3, MinIO, Ceph/RadosGW, Wasabi, DigitalOcean Spaces…)
 * bằng path-style addressing (https://endpoint/bucket/key) — vốn cần thiết cho
 * hệ thống lưu trữ nội bộ không có wildcard DNS cho virtual-host bucket.
 *
 * Thiết kế:
 *  - Body dạng chuỗi/không có body  → ký bằng SHA256 thật (chuẩn).
 *  - Body đẩy từ file (upload part) → dùng `UNSIGNED-PAYLOAD` để stream, không
 *    phải đọc cả file lớn vào RAM chỉ để băm.
 *  - Mọi hàm trả về mảng kết quả hoặc WP_Error (không bao giờ ném exception).
 *
 * Các thao tác: head_bucket, put_object, multipart (create/upload_part/
 * complete/abort), get_object (Range), list_objects_v2, delete_object.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

const SV_S3_EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

/**
 * Managed mode: credentials do iNET nhúng sẵn (hằng số trong sv-s3-config.php).
 * Khi bật, plugin bỏ qua options và ẩn form kết nối; khách không cần cấu hình.
 */
function sv_s3_is_managed() {
	return defined( 'SV_S3_ACCESS_KEY' ) && '' !== (string) SV_S3_ACCESS_KEY;
}

/**
 * Đọc cấu hình S3. Ưu tiên hằng số (managed mode); nếu không thì đọc từ options
 * (self-managed, giải mã key/secret). Trả WP_Error nếu thiếu.
 *
 * @return array|WP_Error
 */
function sv_s3_config() {
	if ( sv_s3_is_managed() ) {
		$endpoint = trim( (string) SV_S3_ENDPOINT );
		$bucket   = trim( (string) SV_S3_BUCKET );
		$access   = (string) SV_S3_ACCESS_KEY;
		$secret   = (string) SV_S3_SECRET_KEY;
		$region   = defined( 'SV_S3_REGION' ) ? trim( (string) SV_S3_REGION ) : '';
		$prefix   = defined( 'SV_S3_PREFIX' ) ? trim( (string) SV_S3_PREFIX ) : '';
		$path_style_const = ! defined( 'SV_S3_PATH_STYLE' ) || SV_S3_PATH_STYLE;
	} else {
		$endpoint = trim( (string) get_option( 'sv_s3_endpoint', '' ) );
		$bucket   = trim( (string) get_option( 'sv_s3_bucket', '' ) );
		$access   = sv_decrypt( get_option( 'sv_s3_access_key', '' ) );
		$secret   = sv_decrypt( get_option( 'sv_s3_secret_key', '' ) );
		$region   = trim( (string) get_option( 'sv_s3_region', '' ) );
		$prefix   = trim( (string) get_option( 'sv_s3_prefix', '' ) );
		$path_style_const = '0' !== (string) get_option( 'sv_s3_path_style', '1' );
	}

	if ( '' === $endpoint || '' === $bucket || '' === $access || '' === $secret ) {
		return new WP_Error( 'sv_s3_incomplete', __( 'Cấu hình S3 chưa đầy đủ (endpoint, bucket, access key, secret key).', 'sitevorx' ) );
	}

	// Endpoint không có scheme → mặc định https.
	if ( ! preg_match( '#^https?://#i', $endpoint ) ) {
		$endpoint = 'https://' . $endpoint;
	}
	$endpoint = untrailingslashit( $endpoint );

	$parts = wp_parse_url( $endpoint );
	if ( empty( $parts['host'] ) ) {
		return new WP_Error( 'sv_s3_bad_endpoint', __( 'Endpoint S3 không hợp lệ.', 'sitevorx' ) );
	}
	$host = $parts['host'];
	if ( ! empty( $parts['port'] ) ) {
		$host .= ':' . $parts['port'];
	}

	return array(
		'endpoint'   => $endpoint,
		'scheme'     => isset( $parts['scheme'] ) ? $parts['scheme'] : 'https',
		'host'       => $host,
		'bucket'     => $bucket,
		'access'     => $access,
		'secret'     => $secret,
		'region'     => '' !== $region ? $region : 'us-east-1',
		'prefix'     => trim( $prefix, '/' ),
		'path_style' => (bool) $path_style_const,
	);
}

/**
 * URI-encode theo chuẩn AWS (RFC 3986). Giữ '/' khi $encode_slash = false.
 */
function sv_s3_uri_encode( $str, $encode_slash = true ) {
	$out = rawurlencode( $str ); // rawurlencode đã không encode '~' (PHP >= 5.3).
	if ( ! $encode_slash ) {
		$out = str_replace( '%2F', '/', $out );
	}
	return $out;
}

/**
 * Ghép key đầy đủ kèm prefix cấu hình.
 */
function sv_s3_full_key( array $cfg, $key ) {
	$key = ltrim( (string) $key, '/' );
	if ( '' !== $cfg['prefix'] ) {
		return $cfg['prefix'] . '/' . $key;
	}
	return $key;
}

/**
 * Thực thi một request S3 đã ký SigV4.
 *
 * @param array  $cfg     Kết quả sv_s3_config().
 * @param string $method  HTTP method (GET/PUT/POST/DELETE/HEAD).
 * @param string $key     Object key (đã gồm prefix nếu cần) — '' cho thao tác bucket.
 * @param array  $args {
 *     @type array  $query        Query params (chưa encode).
 *     @type array  $headers      Header bổ sung (vd Range, Content-Type).
 *     @type string $body         Body dạng chuỗi.
 *     @type string $infile       Đường dẫn file dùng làm body (stream upload).
 *     @type string $outfile      Đường dẫn ghi response body (stream download).
 *     @type int    $timeout      Timeout giây (mặc định 60; upload nên cao hơn).
 * }
 * @return array|WP_Error  ['code'=>int,'headers'=>array,'body'=>string]
 */
function sv_s3_request( array $cfg, $method, $key, array $args = array() ) {
	if ( ! function_exists( 'curl_init' ) ) {
		return new WP_Error( 'sv_s3_no_curl', __( 'PHP cURL extension không khả dụng — bắt buộc cho backup S3.', 'sitevorx' ) );
	}

	$method  = strtoupper( $method );
	$query   = isset( $args['query'] ) && is_array( $args['query'] ) ? $args['query'] : array();
	$headers = isset( $args['headers'] ) && is_array( $args['headers'] ) ? $args['headers'] : array();
	$body    = isset( $args['body'] ) ? $args['body'] : null;
	$infile  = isset( $args['infile'] ) ? $args['infile'] : null;
	$outfile = isset( $args['outfile'] ) ? $args['outfile'] : null;
	$timeout = isset( $args['timeout'] ) ? (int) $args['timeout'] : 60;

	// ----- Canonical path (path-style) -----
	$canonical_uri = '/' . sv_s3_uri_encode( $cfg['bucket'], false );
	if ( '' !== (string) $key ) {
		$canonical_uri .= '/' . sv_s3_uri_encode( $key, false );
	} else {
		$canonical_uri .= '/';
	}

	// ----- Payload hash -----
	if ( null !== $infile ) {
		$payload_hash = 'UNSIGNED-PAYLOAD';
	} elseif ( null !== $body ) {
		$payload_hash = hash( 'sha256', $body );
	} else {
		$payload_hash = SV_S3_EMPTY_SHA256;
	}

	// ----- Thời gian -----
	$amzdate   = gmdate( 'Ymd\THis\Z' );
	$datestamp = gmdate( 'Ymd' );

	// ----- Headers tối thiểu phải ký -----
	$sign_headers = array(
		'host'                 => $cfg['host'],
		'x-amz-content-sha256' => $payload_hash,
		'x-amz-date'           => $amzdate,
	);
	// Gộp header bổ sung (vd Range, Content-Type, x-amz-*) — đều phải ký.
	foreach ( $headers as $hk => $hv ) {
		$sign_headers[ strtolower( $hk ) ] = trim( (string) $hv );
	}
	ksort( $sign_headers );

	$canonical_headers = '';
	$signed_headers    = array();
	foreach ( $sign_headers as $hk => $hv ) {
		$canonical_headers .= $hk . ':' . preg_replace( '/\s+/', ' ', $hv ) . "\n";
		$signed_headers[]   = $hk;
	}
	$signed_headers_str = implode( ';', $signed_headers );

	// ----- Canonical query -----
	ksort( $query );
	$canonical_query_parts = array();
	foreach ( $query as $qk => $qv ) {
		$canonical_query_parts[] = sv_s3_uri_encode( $qk ) . '=' . sv_s3_uri_encode( (string) $qv );
	}
	$canonical_query = implode( '&', $canonical_query_parts );

	$canonical_request = $method . "\n"
		. $canonical_uri . "\n"
		. $canonical_query . "\n"
		. $canonical_headers . "\n"
		. $signed_headers_str . "\n"
		. $payload_hash;

	// ----- String to sign -----
	$scope         = $datestamp . '/' . $cfg['region'] . '/s3/aws4_request';
	$string_to_sign = "AWS4-HMAC-SHA256\n"
		. $amzdate . "\n"
		. $scope . "\n"
		. hash( 'sha256', $canonical_request );

	// ----- Signing key -----
	$k_date    = hash_hmac( 'sha256', $datestamp, 'AWS4' . $cfg['secret'], true );
	$k_region  = hash_hmac( 'sha256', $cfg['region'], $k_date, true );
	$k_service = hash_hmac( 'sha256', 's3', $k_region, true );
	$k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
	$signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );

	$authorization = 'AWS4-HMAC-SHA256 '
		. 'Credential=' . $cfg['access'] . '/' . $scope . ', '
		. 'SignedHeaders=' . $signed_headers_str . ', '
		. 'Signature=' . $signature;

	// ----- Dựng URL gửi đi -----
	$url = $cfg['endpoint'] . $canonical_uri;
	if ( '' !== $canonical_query ) {
		$url .= '?' . $canonical_query;
	}

	// ----- Header gửi qua cURL -----
	$curl_headers = array(
		'Authorization: ' . $authorization,
		'x-amz-content-sha256: ' . $payload_hash,
		'x-amz-date: ' . $amzdate,
	);
	foreach ( $headers as $hk => $hv ) {
		$curl_headers[] = $hk . ': ' . $hv;
	}

	// ----- cURL -----
	$ch = curl_init();
	$resp_headers = array();
	$write_fh     = null;

	curl_setopt( $ch, CURLOPT_URL, $url );
	curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
	curl_setopt( $ch, CURLOPT_HTTPHEADER, $curl_headers );
	curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
	curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 20 );
	curl_setopt( $ch, CURLOPT_TIMEOUT, $timeout );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, true );
	curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, 2 );
	curl_setopt( $ch, CURLOPT_HEADERFUNCTION, function( $c, $h ) use ( &$resp_headers ) {
		$len = strlen( $h );
		$p   = explode( ':', $h, 2 );
		if ( count( $p ) === 2 ) {
			$resp_headers[ strtolower( trim( $p[0] ) ) ] = trim( $p[1] );
		}
		return $len;
	} );

	if ( 'HEAD' === $method ) {
		curl_setopt( $ch, CURLOPT_NOBODY, true );
	}

	if ( null !== $infile ) {
		$fh = @fopen( $infile, 'rb' );
		if ( ! $fh ) {
			curl_close( $ch );
			return new WP_Error( 'sv_s3_infile', sprintf( __( 'Không mở được file để upload: %s', 'sitevorx' ), $infile ) );
		}
		curl_setopt( $ch, CURLOPT_UPLOAD, true );
		curl_setopt( $ch, CURLOPT_INFILE, $fh );
		curl_setopt( $ch, CURLOPT_INFILESIZE, filesize( $infile ) );
		// CURLOPT_UPLOAD đặt method = PUT; ép lại đúng method mong muốn.
		curl_setopt( $ch, CURLOPT_CUSTOMREQUEST, $method );
	} elseif ( null !== $body ) {
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
	}

	if ( null !== $outfile ) {
		$write_fh = @fopen( $outfile, 'ab' );
		if ( ! $write_fh ) {
			curl_close( $ch );
			return new WP_Error( 'sv_s3_outfile', sprintf( __( 'Không ghi được file tải về: %s', 'sitevorx' ), $outfile ) );
		}
		curl_setopt( $ch, CURLOPT_FILE, $write_fh );
	}

	$raw  = curl_exec( $ch );
	$errno = curl_errno( $ch );
	$err   = curl_error( $ch );
	$code  = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
	curl_close( $ch );
	if ( is_resource( $write_fh ) ) {
		fclose( $write_fh );
	}

	if ( $errno ) {
		return new WP_Error( 'sv_s3_curl', sprintf( __( 'Lỗi kết nối S3: %s', 'sitevorx' ), $err ) );
	}

	$body_str = ( null !== $outfile ) ? '' : (string) $raw;

	if ( $code >= 400 ) {
		$msg = sprintf( __( 'S3 trả mã %d.', 'sitevorx' ), $code );
		if ( $body_str && false !== strpos( $body_str, '<Error' ) ) {
			$xml = @simplexml_load_string( $body_str );
			if ( $xml && isset( $xml->Code ) ) {
				$msg = sprintf( '%s: %s', (string) $xml->Code, isset( $xml->Message ) ? (string) $xml->Message : '' );
			}
		}
		return new WP_Error( 'sv_s3_http_' . $code, $msg, array( 'code' => $code, 'body' => $body_str ) );
	}

	return array(
		'code'    => $code,
		'headers' => $resp_headers,
		'body'    => $body_str,
	);
}

// =============================================================================
// Thao tác cấp cao
// =============================================================================

/**
 * Kiểm tra kết nối (HEAD bucket). Trả true hoặc WP_Error.
 *
 * @return true|WP_Error
 */
function sv_s3_head_bucket( array $cfg ) {
	$res = sv_s3_request( $cfg, 'HEAD', '', array( 'timeout' => 20 ) );
	return is_wp_error( $res ) ? $res : true;
}

/**
 * Upload object nhỏ trong 1 lần (PUT).
 */
function sv_s3_put_object( array $cfg, $key, $file_path, $content_type = 'application/octet-stream' ) {
	return sv_s3_request( $cfg, 'PUT', sv_s3_full_key( $cfg, $key ), array(
		'infile'  => $file_path,
		'headers' => array( 'Content-Type' => $content_type ),
		'timeout' => 300,
	) );
}

/**
 * Khởi tạo multipart upload → trả UploadId.
 *
 * @return string|WP_Error
 */
function sv_s3_create_multipart( array $cfg, $key, $content_type = 'application/zip' ) {
	$res = sv_s3_request( $cfg, 'POST', sv_s3_full_key( $cfg, $key ), array(
		'query'   => array( 'uploads' => '' ),
		'headers' => array( 'Content-Type' => $content_type ),
		'timeout' => 30,
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	$xml = @simplexml_load_string( $res['body'] );
	if ( ! $xml || ! isset( $xml->UploadId ) ) {
		return new WP_Error( 'sv_s3_no_uploadid', __( 'Không nhận được UploadId từ S3.', 'sitevorx' ) );
	}
	return (string) $xml->UploadId;
}

/**
 * Upload 1 part (stream từ file một phần). Trả ETag.
 *
 * @param string $part_file Đường dẫn file chứa đúng nội dung part này.
 * @return string|WP_Error  ETag (giữ nguyên dấu nháy kép).
 */
function sv_s3_upload_part( array $cfg, $key, $upload_id, $part_number, $part_file ) {
	$res = sv_s3_request( $cfg, 'PUT', sv_s3_full_key( $cfg, $key ), array(
		'query'   => array( 'partNumber' => (int) $part_number, 'uploadId' => $upload_id ),
		'infile'  => $part_file,
		'timeout' => 600,
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	if ( empty( $res['headers']['etag'] ) ) {
		return new WP_Error( 'sv_s3_no_etag', __( 'Part upload không trả về ETag.', 'sitevorx' ) );
	}
	return $res['headers']['etag'];
}

/**
 * Hoàn tất multipart upload.
 *
 * @param array $parts Mảng [ ['PartNumber'=>1,'ETag'=>'"..."'], ... ].
 * @return true|WP_Error
 */
function sv_s3_complete_multipart( array $cfg, $key, $upload_id, array $parts ) {
	$xml = '<CompleteMultipartUpload>';
	foreach ( $parts as $p ) {
		$etag = $p['ETag'];
		// Đảm bảo ETag có dấu nháy kép bao quanh.
		if ( '"' !== substr( $etag, 0, 1 ) ) {
			$etag = '"' . trim( $etag, '"' ) . '"';
		}
		$xml .= '<Part><PartNumber>' . (int) $p['PartNumber'] . '</PartNumber><ETag>' . $etag . '</ETag></Part>';
	}
	$xml .= '</CompleteMultipartUpload>';

	$res = sv_s3_request( $cfg, 'POST', sv_s3_full_key( $cfg, $key ), array(
		'query'   => array( 'uploadId' => $upload_id ),
		'body'    => $xml,
		'headers' => array( 'Content-Type' => 'application/xml' ),
		'timeout' => 120,
	) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	// CompleteMultipartUpload có thể trả 200 kèm <Error> trong body (S3 quirk).
	if ( false !== strpos( $res['body'], '<Error' ) ) {
		$x = @simplexml_load_string( $res['body'] );
		$msg = ( $x && isset( $x->Code ) ) ? (string) $x->Code . ': ' . (string) $x->Message : __( 'Hoàn tất multipart thất bại.', 'sitevorx' );
		return new WP_Error( 'sv_s3_complete_failed', $msg );
	}
	return true;
}

/**
 * Hủy multipart upload (dọn part dở dang).
 *
 * @return true|WP_Error
 */
function sv_s3_abort_multipart( array $cfg, $key, $upload_id ) {
	$res = sv_s3_request( $cfg, 'DELETE', sv_s3_full_key( $cfg, $key ), array(
		'query'   => array( 'uploadId' => $upload_id ),
		'timeout' => 30,
	) );
	return is_wp_error( $res ) ? $res : true;
}

/**
 * Tải object (hoặc 1 dải byte qua Range) ghi nối vào $outfile.
 *
 * @param string $range vd "bytes=0-5242879" hoặc '' để tải toàn bộ.
 * @return array|WP_Error  ['code'=>, 'headers'=>]
 */
function sv_s3_get_object( array $cfg, $key, $outfile, $range = '' ) {
	$headers = array();
	if ( '' !== $range ) {
		$headers['Range'] = $range;
	}
	return sv_s3_request( $cfg, 'GET', sv_s3_full_key( $cfg, $key ), array(
		'headers' => $headers,
		'outfile' => $outfile,
		'timeout' => 600,
	) );
}

/**
 * HEAD object → trả Content-Length (để tải theo Range) hoặc WP_Error.
 *
 * @return int|WP_Error
 */
function sv_s3_object_size( array $cfg, $key ) {
	$res = sv_s3_request( $cfg, 'HEAD', sv_s3_full_key( $cfg, $key ), array( 'timeout' => 20 ) );
	if ( is_wp_error( $res ) ) {
		return $res;
	}
	return isset( $res['headers']['content-length'] ) ? (int) $res['headers']['content-length'] : 0;
}

/**
 * Liệt kê object theo prefix (gom hết qua continuation token).
 *
 * @param string $prefix Prefix tương đối (sẽ ghép với prefix cấu hình).
 * @return array|WP_Error  Mảng [ ['key'=>, 'size'=>, 'last_modified'=>], ... ].
 */
function sv_s3_list_objects( array $cfg, $prefix = '' ) {
	$full_prefix = sv_s3_full_key( $cfg, $prefix );
	$out         = array();
	$token       = '';
	$guard       = 0;

	do {
		$query = array(
			'list-type' => '2',
			'max-keys'  => '1000',
		);
		if ( '' !== $full_prefix ) {
			$query['prefix'] = $full_prefix;
		}
		if ( '' !== $token ) {
			$query['continuation-token'] = $token;
		}

		$res = sv_s3_request( $cfg, 'GET', '', array( 'query' => $query, 'timeout' => 60 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$xml = @simplexml_load_string( $res['body'] );
		if ( ! $xml ) {
			return new WP_Error( 'sv_s3_list_parse', __( 'Không đọc được danh sách object từ S3.', 'sitevorx' ) );
		}
		if ( isset( $xml->Contents ) ) {
			foreach ( $xml->Contents as $c ) {
				$out[] = array(
					'key'           => (string) $c->Key,
					'size'          => (int) $c->Size,
					'last_modified' => (string) $c->LastModified,
				);
			}
		}
		$token = isset( $xml->NextContinuationToken ) ? (string) $xml->NextContinuationToken : '';
		$guard++;
	} while ( '' !== $token && $guard < 50 );

	return $out;
}

/**
 * Xóa 1 object.
 *
 * @param string $abs_key Key TUYỆT ĐỐI (đã gồm prefix) — dùng trực tiếp giá trị
 *                        Key trả về từ sv_s3_list_objects().
 * @return true|WP_Error
 */
function sv_s3_delete_object_abs( array $cfg, $abs_key ) {
	$res = sv_s3_request( $cfg, 'DELETE', ltrim( $abs_key, '/' ), array( 'timeout' => 30 ) );
	return is_wp_error( $res ) ? $res : true;
}
