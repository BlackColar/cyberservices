<?php
/**
 * Sitevorx Pro — Migrate module (Phase 1: Export)
 *
 * Chunked AJAX-driven exporter that produces a self-contained .zip archive
 * with the full site (DB dump + wp-content/{uploads,themes,plugins}) plus a
 * manifest.json marker that lets the Sitevorx anti-clone subsystem recognise
 * the resulting clone as legitimate on import (Phase 2).
 *
 * Job flow (each step is a separate AJAX request so each one finishes inside
 * the host's max_execution_time):
 *
 *   1. sv_migrate_export_init   — scan filesystem + DB, build job state,
 *                                  create temp working directory, return
 *                                  total work units to the front-end.
 *   2. sv_migrate_export_step   — process next batch of work units (either
 *                                  N files appended to the ZIP, or one DB
 *                                  table dumped to disk). Front-end calls
 *                                  this in a loop until done === true.
 *   3. sv_migrate_export_final  — append manifest.json + db.sql to the ZIP,
 *                                  close it, return the download URL.
 *
 * Import (Phase 2) is intentionally not implemented in this file yet; the UI
 * shows a "coming soon" tab and the menu entry only lists Export today.
 */

if ( ! defined( 'ABSPATH' ) ) exit;

// =============================================================================
// Constants & helpers
// =============================================================================

const SV_MIGRATE_BATCH_FILES     = 40;   // files per inner iteration
const SV_MIGRATE_DB_CHUNK_ROWS   = 500;  // rows per SELECT during dump
const SV_MIGRATE_JOB_TTL         = 6 * HOUR_IN_SECONDS;
const SV_MIGRATE_MAGIC           = 'sitevorx-pro-migrate-v1';
const SV_MIGRATE_TOKEN_LEN       = 32;   // job id length (was 8 in 1.2.0 — security)
const SV_MIGRATE_STEP_BUDGET_SEC = 15;   // wall-clock budget per AJAX step

/**
 * File extensions that are already compressed at the codec level. We append
 * them into the ZIP with CM_STORE (no deflate) — gzipping a JPEG burns CPU
 * for ~0% size win and was the dominant source of slowness on media-heavy
 * sites in 1.2.0/1.2.1.
 */
function sv_migrate_no_compress_exts() {
    return array(
        'jpg','jpeg','png','gif','webp','avif','heic','heif',
        'mp3','mp4','m4a','m4v','mov','avi','mkv','webm','flv','ogg','opus','wav',
        'pdf','zip','gz','bz2','7z','rar','tar','tgz','xz',
        'woff','woff2',
    );
}

/**
 * Root directory inside wp-content/uploads where per-job working files live.
 * Each job gets its own subdir (uploads/sitevorx-migrate/{job_id}/).
 */
function sv_migrate_tmp_root() {
    $u = wp_upload_dir();
    return trailingslashit( $u['basedir'] ) . 'sitevorx-migrate';
}

function sv_migrate_tmp_url_root() {
    $u = wp_upload_dir();
    return trailingslashit( $u['baseurl'] ) . 'sitevorx-migrate';
}

function sv_migrate_state_key( $job_id ) {
    return 'sv_migrate_job_' . preg_replace( '/[^a-z0-9_]/i', '', $job_id );
}

function sv_migrate_get_state( $job_id ) {
    $state = get_transient( sv_migrate_state_key( $job_id ) );
    return is_array( $state ) ? $state : null;
}

function sv_migrate_set_state( $job_id, array $state ) {
    set_transient( sv_migrate_state_key( $job_id ), $state, SV_MIGRATE_JOB_TTL );
}

function sv_migrate_del_state( $job_id ) {
    delete_transient( sv_migrate_state_key( $job_id ) );
}

/**
 * Recursively remove a directory tree. Safe-guards: refuses to act if the
 * target path does not start with sv_migrate_tmp_root().
 */
function sv_migrate_rm_rf( $path ) {
    $root = wp_normalize_path( sv_migrate_tmp_root() );
    $path = wp_normalize_path( $path );
    if ( 0 !== strpos( $path, $root ) ) return; // refuse to delete outside our scope
    if ( ! file_exists( $path ) ) return;
    if ( is_file( $path ) || is_link( $path ) ) {
        @unlink( $path );
        return;
    }
    $dh = @opendir( $path );
    if ( ! $dh ) return;
    while ( false !== ( $f = readdir( $dh ) ) ) {
        if ( '.' === $f || '..' === $f ) continue;
        sv_migrate_rm_rf( $path . '/' . $f );
    }
    closedir( $dh );
    @rmdir( $path );
}

/**
 * Drop guard files at the root tmp dir so Apache cannot directory-list it
 * and direct HTTP access to any archive inside is denied. Defense-in-depth
 * — the real auth gate is the admin-post download endpoint, but if a server
 * is misconfigured or Apache later re-enables Indexes, this still holds.
 *
 * Idempotent: re-running on each export is fine.
 */
function sv_migrate_seal_tmp_root() {
    $root = sv_migrate_tmp_root();
    if ( ! file_exists( $root ) ) {
        wp_mkdir_p( $root );
    }
    $index = $root . '/index.php';
    if ( ! file_exists( $index ) ) {
        @file_put_contents( $index, "<?php // Silence is golden.\n" );
    }
    $htaccess = $root . '/.htaccess';
    if ( ! file_exists( $htaccess ) ) {
        @file_put_contents( $htaccess, "Options -Indexes\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nOrder deny,allow\nDeny from all\n</IfModule>\n" );
    }
    // Best-effort guard for IIS hosts.
    $webconfig = $root . '/web.config';
    if ( ! file_exists( $webconfig ) ) {
        @file_put_contents( $webconfig, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" );
    }
}

/**
 * Ensure the per-job tmp dir exists with an index.php guard.
 */
function sv_migrate_ensure_tmp_dir( $job_id ) {
    sv_migrate_seal_tmp_root();
    $dir = sv_migrate_tmp_root() . '/' . $job_id;
    if ( ! file_exists( $dir ) ) {
        wp_mkdir_p( $dir );
    }
    $guard = $dir . '/index.php';
    if ( ! file_exists( $guard ) ) {
        @file_put_contents( $guard, "<?php // Silence is golden.\n" );
    }
    return $dir;
}

/**
 * Build a list of files to include in the export. Returns a list of
 *   [ 'abs' => absolute path, 'rel' => relative-to-archive path ]
 * The relative path always starts with "wp-content/...".
 *
 * Heavy directories (cache, sitevorx-migrate itself, node_modules) are
 * excluded to keep the archive small and avoid recursion into our own work.
 */
function sv_migrate_collect_files() {
    $files       = array();
    $content_dir = wp_normalize_path( WP_CONTENT_DIR );
    $tmp_root    = wp_normalize_path( sv_migrate_tmp_root() );

    $skip_dirs = array(
        $tmp_root,
        $content_dir . '/cache',
        $content_dir . '/uploads/cache',
        $content_dir . '/wflogs',                  // Wordfence
        $content_dir . '/w3tc-config',             // W3TC
        $content_dir . '/litespeed',               // LiteSpeed cache
        $content_dir . '/et-cache',                // Divi cache
    );
    // Match anywhere in the tree (any dev/VCS directory we don't want shipped).
    $skip_basename_dirs = array( 'node_modules', '.git', '.svn', '.hg', '.idea', '.vscode' );
    $skip_files         = array( '.DS_Store', 'Thumbs.db', 'error_log', 'debug.log' );

    $roots = array(
        $content_dir . '/uploads',
        $content_dir . '/themes',
        $content_dir . '/plugins',
        $content_dir . '/mu-plugins',
    );

    foreach ( $roots as $root ) {
        if ( ! is_dir( $root ) ) continue;
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator( $root, FilesystemIterator::SKIP_DOTS ),
            RecursiveIteratorIterator::LEAVES_ONLY
        );
        foreach ( $it as $f ) {
            $abs = wp_normalize_path( $f->getPathname() );

            // Skip excluded directory subtrees (absolute prefix match).
            $skip = false;
            foreach ( $skip_dirs as $sd ) {
                if ( 0 === strpos( $abs, $sd . '/' ) || $abs === $sd ) { $skip = true; break; }
            }
            if ( $skip ) continue;

            // Skip basename-matched dev dirs anywhere in the path.
            foreach ( $skip_basename_dirs as $bn ) {
                if ( false !== strpos( '/' . $abs . '/', '/' . $bn . '/' ) ) { $skip = true; break; }
            }
            if ( $skip ) continue;

            if ( in_array( basename( $abs ), $skip_files, true ) ) continue;

            // Skip non-regular files (sockets, broken symlinks, etc.)
            if ( ! is_file( $abs ) ) continue;

            // Build relative path inside the archive.
            // E.g. ".../wp-content/uploads/2024/x.jpg" -> "wp-content/uploads/2024/x.jpg"
            $rel = 'wp-content/' . ltrim( substr( $abs, strlen( $content_dir ) ), '/' );

            $files[] = array( 'abs' => $abs, 'rel' => $rel );
        }
    }
    return $files;
}

/**
 * Return the list of DB tables to dump. Only tables matching the **current**
 * site's $wpdb->prefix — using base_prefix here (as 1.2.0 did) is wrong on
 * a multisite sub-site because base_prefix matches `wp_` which also matches
 * every other sub-site's `wp_2_…`, `wp_3_…` tables. That caused sub-site
 * exports to slurp up the entire network's data.
 *
 * Multisite shared tables (users, usermeta on the network) are intentionally
 * NOT included on sub-sites because importing them on a fresh single-site
 * install would conflict; multisite migration needs its own code path which
 * is on the roadmap, not this release.
 */
function sv_migrate_collect_tables() {
    global $wpdb;
    $prefix = $wpdb->prefix;

    $sql_rows = $wpdb->get_col( 'SHOW TABLES' );
    if ( ! is_array( $sql_rows ) ) return array();

    $out = array();
    foreach ( $sql_rows as $t ) {
        if ( 0 === strpos( $t, $prefix ) ) {
            $out[] = $t;
        }
    }
    return array_values( array_unique( $out ) );
}

/**
 * Detect a single-column integer primary key for cursor-based pagination.
 * Returns the column name on success, or '' if the table has no usable PK.
 */
function sv_migrate_table_pk( $table ) {
    global $wpdb;
    $keys = $wpdb->get_results( "SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'", ARRAY_A );
    if ( ! is_array( $keys ) || count( $keys ) !== 1 ) return '';
    $row = $keys[0];
    if ( empty( $row['Column_name'] ) ) return '';
    // Reject composite or non-numeric PKs — they can't be advanced safely with WHERE pk > $last.
    $col_info = $wpdb->get_row( $wpdb->prepare( "SHOW COLUMNS FROM `$table` LIKE %s", $row['Column_name'] ), ARRAY_A );
    if ( ! $col_info || empty( $col_info['Type'] ) ) return '';
    if ( ! preg_match( '/^(int|bigint|mediumint|smallint|tinyint)\b/i', $col_info['Type'] ) ) return '';
    return $row['Column_name'];
}

/**
 * Append a batch of files into the ZIP. Opens, adds, closes — so the archive
 * survives across AJAX requests. Returns the new $files_done offset.
 */
function sv_migrate_append_files_to_zip( $zip_path, array $manifest_files, $offset, $batch ) {
    $zip = new ZipArchive();
    $flag = file_exists( $zip_path ) ? 0 : ZipArchive::CREATE;
    if ( true !== $zip->open( $zip_path, $flag ) ) {
        return new WP_Error( 'zip_open_failed', __( 'Không mở được file ZIP trung gian.', 'sitevorx' ) );
    }
    $no_compress = array_flip( sv_migrate_no_compress_exts() );
    $end = min( count( $manifest_files ), $offset + $batch );
    for ( $i = $offset; $i < $end; $i++ ) {
        $f = $manifest_files[ $i ];
        if ( file_exists( $f['abs'] ) && is_readable( $f['abs'] ) ) {
            // addFile reads lazily during close() so this does not blow memory.
            if ( ! $zip->addFile( $f['abs'], $f['rel'] ) ) {
                continue;
            }
            // 1.2.2: skip deflate for already-compressed media (JPEG/MP4/PDF…) —
            // the codec already squeezed redundancy out, so gzip burns CPU
            // for ~0% size reduction. This was the dominant slowness on
            // media-heavy sites in 1.2.0/1.2.1.
            $ext = strtolower( pathinfo( $f['rel'], PATHINFO_EXTENSION ) );
            if ( $ext !== '' && isset( $no_compress[ $ext ] ) ) {
                // setCompressionIndex exists since PHP 7.0 / libzip 1.0.
                if ( method_exists( $zip, 'setCompressionIndex' ) ) {
                    $zip->setCompressionIndex( $zip->numFiles - 1, ZipArchive::CM_STORE );
                }
            }
        }
    }
    $zip->close();
    return $end;
}

/**
 * Dump a single MySQL table to a .sql fragment file. Streaming-friendly:
 * we never hold the whole table in memory — we SELECT in chunks of
 * SV_MIGRATE_DB_CHUNK_ROWS and fwrite each row's INSERT line immediately.
 */
function sv_migrate_dump_table_to_file( $table, $out_path ) {
    global $wpdb;

    $fp = @fopen( $out_path, 'ab' );
    if ( ! $fp ) {
        return new WP_Error( 'open_failed', sprintf( __( 'Không ghi được %s', 'sitevorx' ), $out_path ) );
    }

    // DROP + CREATE
    $create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
    if ( ! $create || empty( $create[1] ) ) {
        fclose( $fp );
        return new WP_Error( 'no_create', sprintf( __( 'Không lấy được CREATE TABLE cho %s', 'sitevorx' ), $table ) );
    }
    fwrite( $fp, "\n-- ----------------------------\n" );
    fwrite( $fp, "-- Table structure: $table\n" );
    fwrite( $fp, "-- ----------------------------\n" );
    fwrite( $fp, "DROP TABLE IF EXISTS `$table`;\n" );
    fwrite( $fp, $create[1] . ";\n\n" );

    $pk = sv_migrate_table_pk( $table );

    // Pagination strategy: cursor (WHERE pk > $last_pk) when we have a usable
    // integer PK, OFFSET otherwise. OFFSET is O(n²) on InnoDB for large
    // tables (MySQL has to walk-and-discard the first N rows every iteration)
    // so cursor is the right call for wp_postmeta / wp_options / etc.
    if ( $pk ) {
        $last_pk = 0;
        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `$table` WHERE `$pk` > %d ORDER BY `$pk` ASC LIMIT %d",
                    $last_pk,
                    SV_MIGRATE_DB_CHUNK_ROWS
                ),
                ARRAY_A
            );
            if ( empty( $rows ) ) break;
            foreach ( $rows as $row ) {
                if ( isset( $row[ $pk ] ) ) {
                    $last_pk = (int) $row[ $pk ];
                }
                sv_migrate_write_insert_row( $fp, $table, $row );
            }
            // Loop will exit naturally when the next SELECT returns empty.
        }
    } else {
        $offset = 0;
        while ( true ) {
            $rows = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM `$table` LIMIT %d OFFSET %d",
                    SV_MIGRATE_DB_CHUNK_ROWS,
                    $offset
                ),
                ARRAY_A
            );
            if ( empty( $rows ) ) break;
            foreach ( $rows as $row ) {
                sv_migrate_write_insert_row( $fp, $table, $row );
            }
            $offset += SV_MIGRATE_DB_CHUNK_ROWS;
        }
    }

    fwrite( $fp, "\n" );
    fclose( $fp );
    return true;
}

/**
 * Write a single INSERT row to the open dump file handle.
 */
function sv_migrate_write_insert_row( $fp, $table, array $row ) {
    global $wpdb;
    $cols = array();
    $vals = array();
    foreach ( $row as $col => $val ) {
        $cols[] = '`' . $col . '`';
        if ( null === $val ) {
            $vals[] = 'NULL';
        } else {
            // _real_escape wraps mysqli_real_escape_string; it handles binary,
            // unlike esc_sql() which returns an array on array input.
            $vals[] = "'" . $wpdb->_real_escape( (string) $val ) . "'";
        }
    }
    fwrite(
        $fp,
        'INSERT INTO `' . $table . '` (' . implode( ',', $cols ) . ') VALUES (' . implode( ',', $vals ) . ");\n"
    );
}

/**
 * Build the manifest array. Stored as manifest.json inside the archive.
 * The 'magic' + 'origin_fingerprint' fields are what the Phase 2 importer
 * will use to (a) verify the archive is a Sitevorx clone, and (b) ask the
 * anti-clone subsystem to reset its fingerprint cleanly instead of striking.
 */
function sv_migrate_build_manifest( array $state ) {
    global $wpdb;
    return array(
        'magic'              => SV_MIGRATE_MAGIC,
        'plugin_version'     => defined( 'SV_PLUGIN_VERSION' ) ? SV_PLUGIN_VERSION : '',
        'exported_at'        => gmdate( 'c' ),
        'origin_site_url'    => get_option( 'siteurl' ),
        'origin_home_url'    => get_option( 'home' ),
        'origin_fingerprint' => (string) get_option( 'sv_hosting_fingerprint', '' ),
        'origin_admin_email' => get_option( 'admin_email' ),
        'wp_version'         => get_bloginfo( 'version' ),
        'php_version'        => PHP_VERSION,
        'table_prefix'       => $wpdb->prefix,
        'base_prefix'        => $wpdb->base_prefix,
        'is_multisite'       => is_multisite() ? 1 : 0,
        'files_count'        => isset( $state['files_total'] ) ? (int) $state['files_total'] : 0,
        'tables_count'       => isset( $state['tables'] ) ? count( $state['tables'] ) : 0,
        'wp_content_dir'     => str_replace( wp_normalize_path( ABSPATH ), '', wp_normalize_path( WP_CONTENT_DIR ) ),
    );
}

// =============================================================================
// AJAX endpoints
// =============================================================================

/**
 * Phase: init — scan filesystem + DB, set up job state, return totals.
 */
add_action( 'wp_ajax_sv_migrate_export_init', 'sv_migrate_ajax_export_init' );
function sv_migrate_ajax_export_init() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
    }
    check_ajax_referer( 'sv_migrate_nonce', 'nonce' );

    @set_time_limit( 0 );

    // 1.2.1: 32-char token (was 8). 36^32 = 6.3e49 keyspace — uncrackable.
    // 1.2.2: lowercase the token. wp_generate_password() returns mixed case,
    // and downstream handlers run the value through sanitize_key() which
    // lowercases. Storing mixed-case from the start broke nonce verification
    // on the download endpoint (PHP string compare is case-sensitive even
    // though MySQL collation made the transient lookup forgiving).
    $job_id    = 'svmig' . strtolower( wp_generate_password( SV_MIGRATE_TOKEN_LEN, false, false ) );
    $tmp_dir   = sv_migrate_ensure_tmp_dir( $job_id );
    $zip_path  = $tmp_dir . '/site.zip';
    $sql_path  = $tmp_dir . '/database.sql';
    $files_path = $tmp_dir . '/files.json';

    $files  = sv_migrate_collect_files();
    $tables = sv_migrate_collect_tables();

    // 1.2.1: write the file inventory to disk instead of stuffing it into a
    // transient. A 50k-file site serializes to ~10MB which trips MySQL
    // max_allowed_packet on shared hosts and silently fails set_transient().
    @file_put_contents( $files_path, wp_json_encode( $files ) );

    // Prime the SQL fragment with a header.
    @file_put_contents( $sql_path, "-- Sitevorx Pro export\n-- " . gmdate( 'c' ) . "\nSET NAMES utf8mb4;\n\n" );

    $notices = array();
    if ( is_multisite() ) {
        $notices[] = __( 'Site multisite: bản export hiện tại chỉ chứa bảng của sub-site đang đăng nhập (không gồm bảng users/usermeta dùng chung của network). Multisite migration đầy đủ nằm trong roadmap.', 'sitevorx' );
    }

    $state = array(
        'id'             => $job_id,
        'tmp_dir'        => $tmp_dir,
        'zip_path'       => $zip_path,
        'sql_path'       => $sql_path,
        'files_path'     => $files_path,
        'files_total'    => count( $files ),
        'files_done'     => 0,
        'tables'         => $tables,
        'tables_done'    => 0,
        'phase'          => 'files',
        'started_at'     => time(),
        'notices'        => $notices,
    );
    sv_migrate_set_state( $job_id, $state );

    wp_send_json_success( array(
        'job_id'        => $job_id,
        'files_total'   => $state['files_total'],
        'tables_total'  => count( $tables ),
        'total_units'   => $state['files_total'] + count( $tables ),
        'notices'       => $notices,
    ) );
}

/**
 * Phase: step — advance the job by one batch (either files or one DB table).
 */
add_action( 'wp_ajax_sv_migrate_export_step', 'sv_migrate_ajax_export_step' );
function sv_migrate_ajax_export_step() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
    }
    check_ajax_referer( 'sv_migrate_nonce', 'nonce' );
    @set_time_limit( 0 );

    $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
    $state  = sv_migrate_get_state( $job_id );
    if ( ! $state ) {
        wp_send_json_error( array( 'message' => __( 'Phiên xuất đã hết hạn hoặc không tồn tại.', 'sitevorx' ) ), 404 );
    }

    // 1.2.2: time-budget loop. 1.2.0/1.2.1 processed ONE batch per AJAX
    // request and returned, so a 5k-file site needed 125+ HTTP round trips
    // (×200-500ms shared-host RTT = tens of seconds of pure overhead). Now
    // we keep iterating inside the same PHP process until we approach
    // SV_MIGRATE_STEP_BUDGET_SEC, then return progress and let JS call back.
    $deadline = microtime( true ) + SV_MIGRATE_STEP_BUDGET_SEC;
    $files    = null; // lazy-loaded inventory; cached across inner iterations

    while ( microtime( true ) < $deadline ) {
        if ( 'files' === $state['phase'] ) {
            if ( null === $files ) {
                $files_json = '';
                if ( ! empty( $state['files_path'] ) && file_exists( $state['files_path'] ) ) {
                    $files_json = @file_get_contents( $state['files_path'] );
                }
                $files = $files_json ? json_decode( $files_json, true ) : array();
                if ( ! is_array( $files ) ) $files = array();
            }
            $end = sv_migrate_append_files_to_zip(
                $state['zip_path'],
                $files,
                $state['files_done'],
                SV_MIGRATE_BATCH_FILES
            );
            if ( is_wp_error( $end ) ) {
                wp_send_json_error( array( 'message' => $end->get_error_message() ), 500 );
            }
            $state['files_done'] = $end;
            if ( $end >= $state['files_total'] ) {
                $state['phase'] = 'db';
            }
        } elseif ( 'db' === $state['phase'] ) {
            $idx = $state['tables_done'];
            if ( isset( $state['tables'][ $idx ] ) ) {
                $result = sv_migrate_dump_table_to_file( $state['tables'][ $idx ], $state['sql_path'] );
                if ( is_wp_error( $result ) ) {
                    wp_send_json_error( array( 'message' => $result->get_error_message() ), 500 );
                }
                $state['tables_done'] = $idx + 1;
            }
            if ( $state['tables_done'] >= count( $state['tables'] ) ) {
                $state['phase'] = 'finalize';
                break;
            }
        } else {
            break;
        }
    }

    sv_migrate_set_state( $job_id, $state );

    wp_send_json_success( array(
        'phase'        => $state['phase'],
        'files_done'   => $state['files_done'],
        'files_total'  => $state['files_total'],
        'tables_done'  => $state['tables_done'],
        'tables_total' => count( $state['tables'] ),
        'done'         => 'finalize' === $state['phase'],
    ) );
}

/**
 * Phase: finalize — append manifest.json + database.sql to the ZIP, close it,
 * return the download URL. Also clears the per-job state.
 */
add_action( 'wp_ajax_sv_migrate_export_finalize', 'sv_migrate_ajax_export_finalize' );
function sv_migrate_ajax_export_finalize() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
    }
    check_ajax_referer( 'sv_migrate_nonce', 'nonce' );
    @set_time_limit( 0 );

    $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
    $state  = sv_migrate_get_state( $job_id );
    if ( ! $state ) {
        wp_send_json_error( array( 'message' => __( 'Phiên xuất đã hết hạn.', 'sitevorx' ) ), 404 );
    }

    // Write manifest.
    $manifest_path = $state['tmp_dir'] . '/manifest.json';
    @file_put_contents(
        $manifest_path,
        wp_json_encode( sv_migrate_build_manifest( $state ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES )
    );

    // Append SQL + manifest to the ZIP.
    $zip = new ZipArchive();
    if ( true !== $zip->open( $state['zip_path'], file_exists( $state['zip_path'] ) ? 0 : ZipArchive::CREATE ) ) {
        wp_send_json_error( array( 'message' => __( 'Không mở được file ZIP để hoàn tất.', 'sitevorx' ) ), 500 );
    }
    if ( file_exists( $state['sql_path'] ) ) {
        $zip->addFile( $state['sql_path'], 'database.sql' );
    }
    $zip->addFile( $manifest_path, 'manifest.json' );
    $zip->close();

    // Rename the finished archive to a stable, downloadable filename.
    $stamp     = gmdate( 'Ymd-His' );
    $host_slug = sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) );
    $final     = $state['tmp_dir'] . '/' . $host_slug . '-' . $stamp . '.zip';
    @rename( $state['zip_path'], $final );

    $size_bytes = file_exists( $final ) ? filesize( $final ) : 0;

    // 1.2.1: stream through an authenticated admin-post endpoint instead of
    // a direct uploads URL. The .htaccess + 32-char token at the file URL
    // level are defense-in-depth; this is the real auth gate.
    $download_url = add_query_arg(
        array(
            'action'   => 'sv_migrate_download',
            'job_id'   => $state['id'],
            '_wpnonce' => wp_create_nonce( 'sv_migrate_download_' . $state['id'] ),
        ),
        admin_url( 'admin-post.php' )
    );

    $state['phase']         = 'done';
    $state['download_url']  = $download_url;
    $state['download_size'] = $size_bytes;
    $state['download_file'] = basename( $final );
    sv_migrate_set_state( $job_id, $state );

    wp_send_json_success( array(
        'download_url'  => $download_url,
        'download_size' => $size_bytes,
        'filename'      => basename( $final ),
    ) );
}

/**
 * Authenticated file download endpoint (1.2.1).
 *
 * The export sits in wp-content/uploads/sitevorx-migrate/ which, despite
 * .htaccess + 32-char tokens, is best treated as "potentially reachable" on
 * misconfigured / non-Apache servers. This endpoint is the canonical download
 * path: full WP auth + nonce + per-job binding, then stream the file via PHP.
 */
add_action( 'admin_post_sv_migrate_download', 'sv_migrate_ajax_download' );
function sv_migrate_ajax_download() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die( esc_html__( 'Permission denied.', 'sitevorx' ), '', array( 'response' => 403 ) );
    }
    $job_id = isset( $_GET['job_id'] ) ? sanitize_key( wp_unslash( $_GET['job_id'] ) ) : '';
    if ( ! $job_id ) {
        wp_die( esc_html__( 'Missing job id.', 'sitevorx' ), '', array( 'response' => 400 ) );
    }
    check_admin_referer( 'sv_migrate_download_' . $job_id );

    $state = sv_migrate_get_state( $job_id );
    if ( ! $state || empty( $state['download_file'] ) || empty( $state['tmp_dir'] ) ) {
        wp_die( esc_html__( 'Phiên xuất không tồn tại hoặc đã hết hạn.', 'sitevorx' ), '', array( 'response' => 404 ) );
    }

    // Resolve and confine the served path to our tmp dir (no traversal).
    $root = wp_normalize_path( sv_migrate_tmp_root() );
    $path = wp_normalize_path( $state['tmp_dir'] . '/' . $state['download_file'] );
    if ( 0 !== strpos( $path, $root . '/' ) || ! file_exists( $path ) ) {
        wp_die( esc_html__( 'File không tồn tại.', 'sitevorx' ), '', array( 'response' => 404 ) );
    }

    // Drop any buffering — we're about to stream up to 5GB.
    while ( ob_get_level() ) { ob_end_clean(); }

    nocache_headers();
    header( 'Content-Type: application/zip' );
    header( 'Content-Disposition: attachment; filename="' . basename( $path ) . '"' );
    header( 'Content-Length: ' . filesize( $path ) );
    header( 'X-Content-Type-Options: nosniff' );

    $fp = @fopen( $path, 'rb' );
    if ( $fp ) {
        while ( ! feof( $fp ) ) {
            echo fread( $fp, 1024 * 1024 ); // 1MB chunks
            @flush();
        }
        fclose( $fp );
    }
    exit;
}

/**
 * Manual cleanup — admin can wipe a finished job's working directory.
 */
add_action( 'wp_ajax_sv_migrate_export_cleanup', 'sv_migrate_ajax_export_cleanup' );
function sv_migrate_ajax_export_cleanup() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_send_json_error( array( 'message' => __( 'Permission denied.', 'sitevorx' ) ), 403 );
    }
    check_ajax_referer( 'sv_migrate_nonce', 'nonce' );

    $job_id = isset( $_POST['job_id'] ) ? sanitize_key( wp_unslash( $_POST['job_id'] ) ) : '';
    $state  = sv_migrate_get_state( $job_id );
    if ( $state && ! empty( $state['tmp_dir'] ) ) {
        sv_migrate_rm_rf( $state['tmp_dir'] );
    }
    sv_migrate_del_state( $job_id );
    wp_send_json_success();
}

// =============================================================================
// Admin page
// =============================================================================

function sv_display_migrate_page() {
    $nonce = wp_create_nonce( 'sv_migrate_nonce' );
    ?>
    <div class="sv-app-wrapper">
        <div class="sv-app-container">
            <?php sv_render_sidebar( 'migrate' ); ?>
            <div class="sv-content-area">

                <div class="sv-top-banner">
                    <h2><?php esc_html_e( 'Sao Chép Website', 'sitevorx' ); ?></h2>
                    <p><?php esc_html_e( 'Đóng gói toàn bộ website (database + thư mục wp-content) thành một file .zip để chuyển sang hosting/iNET khác, hoặc lưu thành bản sao lưu offline.', 'sitevorx' ); ?></p>
                </div>

                <div class="sv-content-box">
                    <div class="sv-box-header">
                        <span class="dashicons dashicons-migrate" style="color:#0073aa;"></span>
                        <h3><?php esc_html_e( 'Tạo bản sao toàn bộ Website', 'sitevorx' ); ?></h3>
                    </div>
                    <div style="padding: 20px;">
                        <p style="color:#555; margin-top:0;">
                            <?php esc_html_e( 'Bản sao bao gồm: database (toàn bộ bảng có cùng table prefix), wp-content/uploads, wp-content/themes, wp-content/plugins, wp-content/mu-plugins. Các thư mục cache (cache, wflogs, litespeed, et-cache, w3tc-config) được tự động loại trừ.', 'sitevorx' ); ?>
                        </p>
                        <p style="color:#555;">
                            <?php esc_html_e( 'Quá trình chạy theo lô qua AJAX nên không bị timeout — bạn có thể đóng tab và quay lại để hủy/xóa job nếu cần. File kết quả nằm trong wp-content/uploads/sitevorx-migrate/ — bạn nên xóa sau khi tải về.', 'sitevorx' ); ?>
                        </p>

                        <button id="sv-migrate-export-start" class="button button-primary button-large">
                            <span class="dashicons dashicons-database-export" style="vertical-align:middle;"></span>
                            <?php esc_html_e( 'Bắt đầu xuất', 'sitevorx' ); ?>
                        </button>

                        <div id="sv-migrate-progress" style="display:none; margin-top:20px;">
                            <div style="background:#f0f0f1; border-radius:6px; overflow:hidden; height:24px;">
                                <div id="sv-migrate-progress-bar" style="background:linear-gradient(90deg,#0073aa,#00a32a); height:100%; width:0; transition:width 200ms ease;"></div>
                            </div>
                            <p id="sv-migrate-progress-text" style="margin-top:8px; color:#555;"></p>
                        </div>

                        <div id="sv-migrate-result" style="display:none; margin-top:20px; padding:15px; border-radius:6px; background:#e8f5e9; border:1px solid #c8e6c9;">
                            <p style="margin:0 0 10px; color:#1b5e20;"><strong><?php esc_html_e( 'Đã sẵn sàng!', 'sitevorx' ); ?></strong> <span id="sv-migrate-result-msg"></span></p>
                            <a id="sv-migrate-download" href="#" class="button button-primary"><?php esc_html_e( 'Tải file .zip', 'sitevorx' ); ?></a>
                            <button id="sv-migrate-cleanup" class="button" style="margin-left:8px;"><?php esc_html_e( 'Xóa file tạm trên server', 'sitevorx' ); ?></button>
                        </div>

                        <div id="sv-migrate-error" style="display:none; margin-top:20px; padding:15px; border-radius:6px; background:#ffebee; border:1px solid #ffcdd2; color:#b71c1c;"></div>
                    </div>
                </div>

                <div class="sv-content-box">
                    <div class="sv-box-header">
                        <span class="dashicons dashicons-download" style="color:#999;"></span>
                        <h3><?php esc_html_e( 'Nhập (Import) — sắp ra mắt', 'sitevorx' ); ?></h3>
                    </div>
                    <div style="padding: 20px; color:#666;">
                        <p style="margin:0;"><?php esc_html_e( 'Chức năng nhập bản sao (giải nén + restore DB + tự động search-replace URL + reset fingerprint anti-clone) đang trong giai đoạn hoàn thiện. Hiện tại, sau khi xuất file .zip ở trên, bạn có thể giải nén thủ công hoặc dùng plugin All-in-One WP Migration để import tạm.', 'sitevorx' ); ?></p>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <script>
    (function($){
        var nonce = '<?php echo esc_js( $nonce ); ?>';
        var ajaxurl = '<?php echo esc_js( admin_url( 'admin-ajax.php' ) ); ?>';
        var jobId = null;

        function fmtBytes(n){
            if (!n) return '0 B';
            var u = ['B','KB','MB','GB','TB']; var i = 0; var v = n;
            while (v >= 1024 && i < u.length - 1) { v /= 1024; i++; }
            return v.toFixed(v < 10 && i > 0 ? 2 : 1) + ' ' + u[i];
        }
        function showError(msg){
            $('#sv-migrate-progress').hide();
            $('#sv-migrate-error').text(msg).show();
            $('#sv-migrate-export-start').prop('disabled', false);
        }
        function setProgress(done, total, label){
            var pct = total > 0 ? Math.min(100, Math.round(done * 100 / total)) : 0;
            $('#sv-migrate-progress-bar').css('width', pct + '%');
            $('#sv-migrate-progress-text').text(label + ' — ' + pct + '% (' + done + ' / ' + total + ')');
        }
        function call(action, data){
            data = data || {};
            data.action = action; data.nonce = nonce;
            return $.post(ajaxurl, data);
        }
        function stepLoop(filesTotal, tablesTotal){
            call('sv_migrate_export_step', { job_id: jobId }).done(function(resp){
                if (!resp || !resp.success){
                    showError(resp && resp.data && resp.data.message ? resp.data.message : 'Bước xuất thất bại.');
                    return;
                }
                var d = resp.data;
                var totalUnits = filesTotal + tablesTotal;
                var doneUnits = d.files_done + d.tables_done;
                var label = (d.phase === 'files') ? 'Đang nén file' : (d.phase === 'db' ? 'Đang xuất database' : 'Hoàn tất');
                setProgress(doneUnits, totalUnits, label);
                if (d.done){
                    call('sv_migrate_export_finalize', { job_id: jobId }).done(function(fin){
                        if (!fin || !fin.success){
                            showError(fin && fin.data && fin.data.message ? fin.data.message : 'Đóng gói thất bại.');
                            return;
                        }
                        $('#sv-migrate-progress').hide();
                        $('#sv-migrate-result-msg').text('File ' + fin.data.filename + ' (' + fmtBytes(fin.data.download_size) + ')');
                        $('#sv-migrate-download').attr('href', fin.data.download_url);
                        $('#sv-migrate-result').show();
                        $('#sv-migrate-export-start').prop('disabled', false);
                    }).fail(function(){ showError('Không gọi được endpoint finalize.'); });
                } else {
                    setTimeout(function(){ stepLoop(filesTotal, tablesTotal); }, 50);
                }
            }).fail(function(){ showError('Mất kết nối với server trong khi xuất.'); });
        }

        $('#sv-migrate-export-start').on('click', function(){
            $('#sv-migrate-error').hide();
            $('#sv-migrate-result').hide();
            $(this).prop('disabled', true);
            $('#sv-migrate-progress').show();
            setProgress(0, 1, 'Đang khởi tạo');
            call('sv_migrate_export_init').done(function(resp){
                if (!resp || !resp.success){
                    showError(resp && resp.data && resp.data.message ? resp.data.message : 'Không khởi tạo được job xuất.');
                    return;
                }
                jobId = resp.data.job_id;
                stepLoop(resp.data.files_total, resp.data.tables_total);
            }).fail(function(){ showError('Không gọi được endpoint init.'); });
        });

        $('#sv-migrate-cleanup').on('click', function(){
            if (!jobId) return;
            call('sv_migrate_export_cleanup', { job_id: jobId }).done(function(){
                $('#sv-migrate-result').hide();
                jobId = null;
            });
        });
    })(jQuery);
    </script>
    <?php
}
