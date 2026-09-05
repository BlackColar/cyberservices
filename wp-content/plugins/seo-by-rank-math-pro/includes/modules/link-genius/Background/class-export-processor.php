<?php
/**
 * Export Processor for Link Genius.
 *
 * Handles background export processing for large datasets.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Background;

use WP_Error;
use RankMath\Helper;
use RankMathPro\Link_Genius\Data\Query_Builder;

defined( 'ABSPATH' ) || exit;

/**
 * Export Processor class.
 */
class Export_Processor extends \WP_Background_Process {

	/**
	 * Action.
	 *
	 * @var string
	 */
	protected $action = 'link_genius_export';

	/**
	 * Main instance.
	 *
	 * Ensure only one instance is loaded or can be loaded.
	 *
	 * @return Export_Processor
	 */
	public static function get() {
		static $instance;

		if ( is_null( $instance ) && ! ( $instance instanceof Export_Processor ) ) {
			$instance = new Export_Processor();
		}

		return $instance;
	}

	/**
	 * Get the maximum number of records for immediate export.
	 *
	 * @return int Maximum records for immediate export.
	 */
	public static function get_export_limit() {
		/**
		 * Filter: Allow developers to change the immediate export limit.
		 *
		 * @param int $limit Maximum number of records for immediate export. Default 1000.
		 */
		return apply_filters( 'rank_math/link_genius/export_limit', 1000 );
	}

	/**
	 * Start export process.
	 *
	 * @param string $type        Export type (links or posts).
	 * @param string $format      Export format (csv, json, excel).
	 * @param array  $filters     Filters to apply to the export.
	 * @param array  $columns     Column definitions.
	 * @param int    $total_count Total number of records to export.
	 * @return array Export job details.
	 */
	public function start( $type, $format, $filters, $columns, $total_count ) {
		// Create a unique job ID.
		$job_id = wp_generate_password( 12, false );

		// Create export directory.
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/rank-math-exports';

		if ( ! file_exists( $export_dir ) ) {
			wp_mkdir_p( $export_dir );
		}

		// Generate filename.
		$timestamp = gmdate( 'Ymd_His' );
		$extension = 'csv' === $format ? 'csv' : ( 'json' === $format ? 'json' : 'xls' );
		$filename  = "link-genius-{$type}-{$timestamp}.{$extension}";
		$file_path = $export_dir . '/' . $filename;

		// Initialize file with headers.
		$this->init_export_file( $file_path, $format, $columns );

		// Store export job metadata in options.
		$job_data = [
			'job_id'      => $job_id,
			'type'        => $type,
			'format'      => $format,
			'filters'     => $filters,
			'columns'     => $columns,
			'file_path'   => $file_path,
			'total_count' => $total_count,
			'processed'   => 0,
			'user_email'  => wp_get_current_user()->user_email,
			'created_at'  => current_time( 'mysql' ),
		];

		update_option( "rank_math_export_{$job_id}", $job_data, false );

		// Add notification.
		Helper::add_notification(
			sprintf(
				/* translators: %d: number of records */
				__( 'Export started for %s records. It might take a few minutes to complete.', 'rank-math-pro' ),
				number_format_i18n( $total_count )
			),
			[
				'type'    => 'info',
				'id'      => 'rank_math_export_started',
				'classes' => 'rank-math-notice',
			]
		);

		/**
		 * Filter: Allow developers to change the export chunk size.
		 *
		 * @param int $chunk_size Number of records to fetch per task. Default 5000.
		 */
		$chunk_size = apply_filters( 'rank_math/link_genius/export_chunk_size', 5000 );

		// Queue tasks for each chunk.
		$num_chunks = ceil( $total_count / $chunk_size );
		for ( $i = 0; $i < $num_chunks; $i++ ) {
			$this->push_to_queue(
				[
					'job_id' => $job_id,
					'offset' => $i * $chunk_size,
					'limit'  => $chunk_size,
				]
			);
		}

		$this->save()->dispatch();

		return [
			'success' => true,
			'job_id'  => $job_id,
			'message' => __( 'Export scheduled. You will receive an email when it\'s ready.', 'rank-math-pro' ),
		];
	}

	/**
	 * Cleanup old export files (older than 7 days).
	 *
	 * @return void
	 */
	public static function cleanup_old_exports() {
		$upload_dir = wp_upload_dir();
		$export_dir = $upload_dir['basedir'] . '/rank-math-exports';

		if ( ! file_exists( $export_dir ) ) {
			return;
		}

		$files = glob( $export_dir . '/*' );
		$now   = time();

		foreach ( $files as $file ) {
			if ( is_file( $file ) ) {
				if ( $now - filemtime( $file ) >= 7 * DAY_IN_SECONDS ) {
					wp_delete_file( $file );
				}
			}
		}
	}

	/**
	 * Task to perform - processes one chunk of export data.
	 *
	 * @param array $chunk_data Chunk data with job_id, offset, limit.
	 * @return false
	 */
	protected function task( $chunk_data ) {
		$job_id = $chunk_data['job_id'];

		// Get job metadata from options.
		$job_meta = get_option( "rank_math_export_{$job_id}" );
		if ( ! $job_meta ) {
			return false; // Job not found, remove from queue.
		}

		try {
			// Fetch this chunk of data with filters (including sorting).
			$args = array_merge(
				$job_meta['filters'],
				[
					'per_page' => $chunk_data['limit'],
					'offset'   => $chunk_data['offset'],
				]
			);

			// Ensure orderby and order are properly set from filters if provided.
			if ( isset( $job_meta['filters']['orderby'] ) ) {
				$args['orderby'] = $job_meta['filters']['orderby'];
			}
			if ( isset( $job_meta['filters']['order'] ) ) {
				$args['order'] = $job_meta['filters']['order'];
			}

			$data = $job_meta['type'] === 'posts'
				? Query_Builder::get_posts( $args )
				: Query_Builder::get_links( $args );

			if ( ! empty( $data ) ) {
				// Append data to file.
				$this->append_to_export_file(
					$job_meta['file_path'],
					$data,
					$job_meta['format'],
					$job_meta['columns'],
					$job_meta['processed']
				);

				// Update processed count.
				$job_meta['processed'] += count( $data );
				update_option( "rank_math_export_{$job_id}", $job_meta, false );
			}

			return false; // Successfully processed, remove from queue.

		} catch ( \Exception $e ) {
			// Log error and fail gracefully.
			error_log( "Link Genius export error (job {$job_id}): " . $e->getMessage() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			return false;
		}
	}

	/**
	 * Complete export processing.
	 *
	 * Called when all chunks have been processed.
	 */
	protected function complete() {
		// Get all pending export jobs.
		global $wpdb;
		$jobs = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			WHERE option_name LIKE 'rank_math_export_%'"
		);

		foreach ( $jobs as $option_name ) {
			$job = get_option( $option_name );

			if ( ! $job || empty( $job['file_path'] ) ) {
				delete_option( $option_name );
				continue;
			}

			// Finalize the file based on format.
			$this->finalize_export_file( $job['file_path'], $job['format'] );

			// Get file URL for download link.
			$job['file_url'] = self::get_file_url( $job['file_path'] );

			// Send completion email.
			self::send_export_email( $job );

			// Add success notification.
			Helper::add_notification(
				sprintf(
					/* translators: 1: Record count, 2: Download URL */
					__( 'Export completed: %1$s records. %2$s', 'rank-math-pro' ),
					number_format_i18n( $job['processed'] ),
					'<a href="' . esc_url( $job['file_url'] ) . '" target="_blank">' . __( 'Download', 'rank-math-pro' ) . '</a>'
				),
				[
					'type'    => 'success',
					'id'      => 'rank_math_export_complete_' . $job['job_id'],
					'classes' => 'rank-math-notice',
				]
			);

			// Clean up job metadata.
			delete_option( $option_name );
		}

		parent::complete();
	}

	/**
	 * Initialize export file with headers.
	 *
	 * Note: Using direct filesystem functions instead of WP_Filesystem because:
	 * - WP_Filesystem doesn't support fputcsv() for CSV formatting
	 * - File locking (flock) is required for concurrent writes in background processes
	 * - This is a background process writing to wp-uploads, not user-facing files
	 *
	 * @param string $file_path File path.
	 * @param string $format    Export format (csv, json, excel).
	 * @param array  $columns   Column definitions.
	 * @return bool True on success, false on failure.
	 */
	private function init_export_file( $file_path, $format, $columns ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Direct filesystem access required for CSV export and file locking.
		$fp = fopen( $file_path, 'w' );

		if ( ! $fp ) {
			return false;
		}

		switch ( $format ) {
			case 'csv':
				// Write CSV header row.
				$headers = array_column( $columns, 'label' );
				fputcsv( $fp, $headers );
				break;

			case 'json':
				// Start JSON array.
				fwrite( $fp, "[\n" );
				break;

			case 'excel':
				// Start HTML table with header row.
				fwrite( $fp, '<table><thead><tr>' );
				foreach ( $columns as $column ) {
					fwrite( $fp, '<th>' . esc_html( $column['label'] ) . '</th>' );
				}
				fwrite( $fp, '</tr></thead><tbody>' );
				break;
		}

		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		return true;
	}

	/**
	 * Append data to export file.
	 *
	 * Uses file locking to prevent corruption during concurrent writes.
	 *
	 * Note: Using direct filesystem functions instead of WP_Filesystem because:
	 * - File locking (flock) is critical for preventing data corruption in background processes
	 * - WP_Filesystem doesn't support flock() or fputcsv()
	 * - This runs in background queue, not during user requests
	 *
	 * @param string $file_path      File path.
	 * @param array  $data           Data rows to append.
	 * @param string $format         Export format (csv, json, excel).
	 * @param array  $columns        Column definitions.
	 * @param int    $processed_count Number of records already processed.
	 * @return bool True on success, false on failure.
	 */
	private function append_to_export_file( $file_path, $data, $format, $columns, $processed_count ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions -- Direct filesystem access required for CSV export and file locking.
		$fp = fopen( $file_path, 'a' );

		if ( ! $fp ) {
			return false;
		}

		// Acquire exclusive lock to prevent corruption.
		flock( $fp, LOCK_EX );

		switch ( $format ) {
			case 'csv':
				foreach ( $data as $row ) {
					$csv_row = [];
					foreach ( $columns as $column ) {
						$csv_row[] = $row->{$column['key']} ?? '';
					}
					fputcsv( $fp, $csv_row );
				}
				break;

			case 'json':
				foreach ( $data as $index => $row ) {
					// Add comma before item if not the first record in entire export.
					if ( $processed_count > 0 || $index > 0 ) {
						fwrite( $fp, ",\n" );
					}

					// Build row with only requested columns.
					$clean_row = [];
					foreach ( $columns as $column ) {
						$clean_row[ $column['key'] ] = $row->{$column['key']} ?? null;
					}

					// Write row as JSON (with indentation).
					$json_row = wp_json_encode( $clean_row, JSON_PRETTY_PRINT );
					$json_row = str_replace( "\n", "\n  ", $json_row );
					fwrite( $fp, '  ' . $json_row );
				}
				break;

			case 'excel':
				foreach ( $data as $row ) {
					fwrite( $fp, '<tr>' );
					foreach ( $columns as $column ) {
						$value = $row->{$column['key']} ?? '';
						fwrite( $fp, '<td>' . esc_html( $value ) . '</td>' );
					}
					fwrite( $fp, '</tr>' );
				}
				break;
		}

		// Release lock and close file.
		flock( $fp, LOCK_UN );
		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions

		return true;
	}

	/**
	 * Finalize export file (add closing tags/brackets).
	 *
	 * Note: Using direct filesystem functions for consistency with init/append methods.
	 *
	 * @param string $file_path File path.
	 * @param string $format    Export format (csv, json, excel).
	 * @return bool True on success, false on failure.
	 */
	private function finalize_export_file( $file_path, $format ) {
		if ( 'csv' === $format ) {
			return true; // CSV doesn't need finalization.
		}

		// phpcs:disable WordPress.WP.AlternativeFunctions -- Direct filesystem access required for consistency with export methods.
		$fp = fopen( $file_path, 'a' );

		if ( ! $fp ) {
			return false;
		}

		switch ( $format ) {
			case 'json':
				// Close JSON array.
				fwrite( $fp, "\n]" );
				break;

			case 'excel':
				// Close HTML table.
				fwrite( $fp, '</tbody></table>' );
				break;
		}

		fclose( $fp );
		// phpcs:enable WordPress.WP.AlternativeFunctions
		return true;
	}

	/**
	 * Get file URL from path.
	 *
	 * @param string $file_path File path.
	 * @return string File URL.
	 */
	private static function get_file_url( $file_path ) {
		$upload_dir = wp_upload_dir();
		$file_url   = str_replace( $upload_dir['basedir'], $upload_dir['baseurl'], $file_path );
		return $file_url;
	}

	/**
	 * Send export completion email.
	 *
	 * @param array $job Job data.
	 * @return void
	 */
	private static function send_export_email( $job ) {
		$to      = $job['user_email'];
		$subject = __( 'Rank Math: Your export is ready', 'rank-math-pro' );

		// Get admin email from settings or use site admin email.
		$admin_email = get_option( 'admin_email' );

		$message = sprintf(
			/* translators: 1: Download URL, 2: Record count */
			__( 'Your Link Genius export is ready for download.%1$s%1$sTotal records: %2$d%1$s%1$sDownload link: %3$s%1$s%1$sNote: This link will expire in 24 hours.', 'rank-math-pro' ),
			"\n",
			$job['total_count'],
			$job['file_url']
		);

		$headers = [ 'Content-Type: text/plain; charset=UTF-8' ];

		wp_mail( $to, $subject, $message, $headers );
	}
}
