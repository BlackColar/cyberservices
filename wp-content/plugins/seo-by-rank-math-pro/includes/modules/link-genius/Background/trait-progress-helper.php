<?php
/**
 * Progress Helper trait for background processors.
 *
 * Provides common progress tracking methods to eliminate duplication
 * across all background processors.
 *
 * @since      1.0.100
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Link_Genius\Background;

defined( 'ABSPATH' ) || exit;

/**
 * Progress_Helper trait.
 *
 * Centralizes progress calculation and state management logic
 * used across all background processors.
 */
trait Progress_Helper {

	/**
	 * Get the state option name for this processor.
	 *
	 * Must be implemented by using class to return unique option name.
	 *
	 * @return string Option name for state storage.
	 */
	abstract protected function get_state_option_name();

	/**
	 * Get current processor state.
	 *
	 * @return array State data.
	 */
	protected function get_state() {
		global $wpdb;
		$option_name = $this->get_state_option_name();

		// Bypass all caching and read directly from database.
		$value = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM $wpdb->options WHERE option_name = %s",
				$option_name
			)
		);

		if ( false === $value || null === $value ) {
			return [];
		}

		return maybe_unserialize( $value );
	}

	/**
	 * Set entire processor state.
	 *
	 * @param array $state State data to store.
	 */
	protected function set_state( $state ) {
		update_option( $this->get_state_option_name(), $state, false );
	}


	/**
	 * Calculate progress percentage.
	 *
	 * @param int $processed Number of items processed.
	 * @param int $total     Total number of items.
	 * @return int Percentage (0-100).
	 */
	protected static function calculate_percent( $processed, $total ) {
		return $total > 0 ? min( 100, (int) round( ( $processed / $total ) * 100 ) ) : 0;
	}

	/**
	 * Check if operation is currently active.
	 *
	 * Operation is active if:
	 * - Total is set (> 0)
	 * - Not all items processed yet
	 * - No completion timestamp
	 *
	 * @param array $state State data.
	 * @return bool True if active.
	 */
	protected static function is_operation_active( $state ) {
		if ( empty( $state ) ) {
			return false;
		}

		$total     = $state['total'] ?? 0;
		$processed = $state['processed'] ?? 0;

		// If completed_at is set, operation is done.
		if ( isset( $state['completed_at'] ) && null !== $state['completed_at'] ) {
			return false;
		}

		// Active if total is set and not all processed.
		return $total > 0 && $processed < $total;
	}

	/**
	 * Format state data into standard progress response.
	 *
	 * @param array $state State data.
	 * @return array Formatted progress data.
	 */
	protected static function format_progress( $state ) {
		$total     = $state['total'] ?? 0;
		$processed = $state['processed'] ?? 0;
		$failed    = $state['failed'] ?? 0;
		$is_active = self::is_operation_active( $state );

		$progress = [
			'active'    => $is_active,
			'total'     => $total,
			'processed' => $processed,
			'percent'   => self::calculate_percent( $processed + $failed, $total ),
		];

		// Add optional fields if present in state.
		if ( isset( $state['failed'] ) ) {
			$progress['failed'] = $failed;
		}

		if ( isset( $state['errors'] ) ) {
			$progress['errors'] = $state['errors'];
		}

		if ( isset( $state['started_at'] ) ) {
			$progress['started_at'] = $state['started_at'];
		}

		if ( isset( $state['completed_at'] ) ) {
			$progress['completed_at'] = $state['completed_at'];
		}

		return $progress;
	}

	/**
	 * Initialize progress tracking state.
	 *
	 * @param int   $total            Total items to process.
	 * @param array $additional_state Additional state data to merge.
	 */
	protected function init_progress_state( $total, $additional_state = [] ) {
		$state = array_merge(
			[
				'total'        => $total,
				'processed'    => 0,
				'failed'       => 0,
				'errors'       => 0,
				'started_at'   => current_time( 'mysql' ),
				'completed_at' => null,
			],
			$additional_state
		);

		$this->set_state( $state );
	}

	/**
	 * Mark operation as completed in state.
	 */
	protected function mark_completed() {
		$state                 = $this->get_state();
		$state['completed_at'] = current_time( 'mysql' );
		$this->set_state( $state );
	}

	/**
	 * Increment progress counters.
	 *
	 * Updates processed and/or failed counts in a single operation.
	 * Consolidates duplicate progress update logic across processors.
	 *
	 * @param int $processed Number of items successfully processed in this batch.
	 * @param int $failed    Number of items that failed in this batch (optional).
	 */
	protected function increment_progress( $processed = 0, $failed = 0 ) {
		$state              = $this->get_state();
		$state['processed'] = ( $state['processed'] ?? 0 ) + $processed;

		if ( $failed > 0 ) {
			$state['failed'] = ( $state['failed'] ?? 0 ) + $failed;
		}

		$this->set_state( $state );
	}

	/**
	 * Clear all state data.
	 */
	protected function clear_state() {
		delete_option( $this->get_state_option_name() );
	}

	/**
	 * Delete all queued batches directly from database.
	 *
	 * Memory-efficient alternative to cancel_process() that doesn't load batches into memory.
	 * Uses direct SQL deletion to avoid memory exhaustion with large queues.
	 */
	protected function delete_batches_from_db() {
		global $wpdb;

		\RankMath\Helpers\DB::query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( $this->identifier . '_batch_' ) . '%'
			)
		);
	}
}
