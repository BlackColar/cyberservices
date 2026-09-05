<?php
/**
 * Coordinated Processor trait for background processors.
 *
 * Provides process coordination logic to eliminate duplication
 * of lock acquisition/release patterns across processors.
 *
 * @since      1.0.100
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Link_Genius\Background;

defined( 'ABSPATH' ) || exit;

/**
 * Coordinated_Processor trait.
 *
 * Centralizes lock management and write coordination logic
 * used across background processors to prevent concurrent operations.
 */
trait Coordinated_Processor {

	/**
	 * Get the operation name for lock coordination.
	 *
	 * Must be implemented by using class to return unique operation identifier.
	 *
	 * @return string Operation name for coordination.
	 */
	abstract protected function get_operation_name();

	/**
	 * Cancel the current operation.
	 *
	 * Uses memory-efficient batch deletion to avoid memory exhaustion with large queues.
	 * Child classes can override this to add custom cleanup logic.
	 */
	public function cancel() {
		$operation = $this->get_operation_name();

		// Stop the background process by clearing the cron event.
		$this->clear_scheduled_event();

		// Delete all queued batches (memory efficient).
		$this->delete_batches_from_db();

		// Unlock the parent process lock (from WP_Background_Process).
		$this->unlock_process();

		// Clean up coordination state.
		Process_Coordinator::mark_write_complete( $operation );
		Process_Coordinator::release_lock( $operation );

		// Clear progress state if trait is available.
		if ( method_exists( $this, 'clear_state' ) ) {
			$this->clear_state();
		}
	}

	/**
	 * Handle task with process coordination.
	 *
	 * Wraps the parent handle() method with lock acquisition/release
	 * and write state tracking to prevent concurrent operations.
	 */
	protected function handle() {
		$operation = $this->get_operation_name();

		// Acquire lock before processing.
		if ( ! Process_Coordinator::acquire_lock( $operation ) ) {
			return; // Another process is running.
		}

		// Mark write as active.
		Process_Coordinator::mark_write_active( $operation );

		try {
			// Call parent handle method to process the batch.
			parent::handle();
		} finally {
			// Always mark write complete and release lock, even if exception occurs.
			Process_Coordinator::mark_write_complete( $operation );
			Process_Coordinator::release_lock( $operation );
		}
	}
}
