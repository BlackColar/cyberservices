<?php
/**
 * Trait for handling errors in background processors.
 *
 * Provides common error logging functionality for all Link Genius background processors.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Background;

defined( 'ABSPATH' ) || exit;

/**
 * Background_Processor_Error_Handler trait.
 *
 * Provides standardized error logging and tracking for background processors.
 */
trait Background_Processor_Error_Handler {

	/**
	 * Log error during processing.
	 *
	 * @param string $processor_name Name of the processor (e.g., 'Regenerate', 'Bulk Update').
	 * @param string $error_option   Option name for storing error count.
	 * @param mixed  $item           Item identifier (post ID, link ID, batch ID, etc.).
	 * @param string $message        Error message.
	 */
	protected function log_error( $processor_name, $error_option, $item, $message ) {
		$item_label = $this->format_item_label( $item );

		$log_entry = sprintf(
			'[Link Genius %s] Error processing %s: %s',
			$processor_name,
			$item_label,
			$message
		);

		// Log to PHP error log for debugging.
		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $log_entry );

		// Increment error count for completion message.
		$errors = (int) get_option( $error_option, 0 );
		update_option( $error_option, $errors + 1, false );
	}

	/**
	 * Handle task error with retry logic.
	 *
	 * @param array  $item_data      Task item data.
	 * @param string $processor_name Name of the processor.
	 * @param string $error_option   Option name for storing error count.
	 * @param string $error_message  Error message.
	 * @param int    $max_retries    Maximum number of retries (default: 3).
	 * @return array|false Array to retry, false to give up.
	 */
	protected function handle_task_error( $item_data, $processor_name, $error_option, $error_message, $max_retries = 3 ) {
		// Log the error.
		$this->log_error( $processor_name, $error_option, $item_data, $error_message );

		// Add retry tracking.
		if ( ! isset( $item_data['retry_count'] ) ) {
			$item_data['retry_count'] = 0;
		}

		++$item_data['retry_count'];

		// Retry up to max retries.
		if ( $item_data['retry_count'] < $max_retries ) {
			return $item_data; // Return item to retry.
		}

		// Give up after max retries.
		return false;
	}

	/**
	 * Format item label for error messages.
	 *
	 * @param mixed $item Item identifier.
	 * @return string Formatted label.
	 */
	private function format_item_label( $item ) {
		if ( is_array( $item ) ) {
			return 'batch';
		}

		if ( is_numeric( $item ) ) {
			return "item #{$item}";
		}

		return (string) $item;
	}
}
