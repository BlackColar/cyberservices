<?php
/**
 * Background process for regenerating internal links.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Background;

use RankMath\Helper;
use RankMath\Helpers\DB;
use RankMathPro\Link_Genius\Data\Query_Builder;
use RankMathPro\Link_Genius\Services\Batch_Helper;
use RankMathPro\Link_Genius\Services\Link_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Regenerate_Links class.
 */
class Regenerate_Links extends \WP_Background_Process {

	use Background_Processor_Error_Handler;
	use Progress_Helper;
	use Coordinated_Processor;

	/**
	 * Action.
	 *
	 * @var string
	 */
	protected $action = 'regenerate_internal_links';

	/**
	 * Main instance.
	 *
	 * Ensure only one instance is loaded or can be loaded.
	 *
	 * @return Regenerate_Links
	 */
	public static function get() {
		static $instance;

		if ( is_null( $instance ) && ! ( $instance instanceof Regenerate_Links ) ) {
			$instance = new Regenerate_Links();
		}

		return $instance;
	}

	/**
	 * Start regenerating links.
	 *
	 * Truncates tables and queues all posts for background processing.
	 *
	 * @return bool True on success.
	 */
	public function start() {
		global $wpdb;

		// Clear existing data first.
		$this->truncate_tables();

		// Get all post types to process.
		$post_types = Helper::get_accessible_post_types();
		unset( $post_types['attachment'] );

		/**
		 * Filter: Allow developers to change the regeneration chunk size.
		 *
		 * Larger chunk sizes reduce background job overhead but increase
		 * individual task execution time.
		 *
		 * @param int $chunk_size Number of posts per batch. Default 200.
		 */
		$chunk_size = (int) apply_filters( 'rank_math/link_genius/regenerate_chunk_size', 200 );

		// Get total count first (lightweight query).
		$post_type_keys         = array_keys( $post_types );
		$post_type_placeholders = implode( ',', array_fill( 0, count( $post_type_keys ), '%s' ) );
		$count_query            = "SELECT COUNT(ID) FROM {$wpdb->posts}
			WHERE post_type IN ($post_type_placeholders)
			AND post_status IN ('publish', 'future')";

		$total_posts = (int) DB::get_var( $wpdb->prepare( $count_query, ...$post_type_keys ) );

		// If no posts found, return false.
		if ( $total_posts === 0 ) {
			return false;
		}

		// Initialize progress tracking with consolidated state.
		$this->init_progress_state( $total_posts );

		// Queue posts in batches using cursor-based pagination.
		$batch_query = "SELECT ID FROM {$wpdb->posts}
			WHERE post_type IN ($post_type_placeholders)
			AND post_status IN ('publish', 'future')
			AND ID > %d
			ORDER BY ID ASC
			LIMIT %d";

		$cursor = 0; // Start from beginning.
		while ( true ) {
			$batch_ids = DB::get_col(
				$wpdb->prepare(
					$batch_query,
					...array_merge( $post_type_keys, [ $cursor, $chunk_size ] )
				)
			);

			if ( empty( $batch_ids ) ) {
				break; // No more posts.
			}

			$this->push_to_queue( $batch_ids );

			// Update cursor to last ID in this batch.
			$cursor = end( $batch_ids );
		}

		// Save and dispatch.
		$this->save()->dispatch();

		return true;
	}

	/**
	 * Check if regeneration is currently active.
	 *
	 * @return bool True if regeneration is active.
	 */
	public function is_active() {
		return self::is_operation_active( $this->get_state() );
	}

	/**
	 * Check if regeneration has any progress data (active or can be cancelled).
	 *
	 * @return bool True if there's progress data to cancel/clear.
	 */
	public function has_progress_data() {
		$state = $this->get_state();
		return ! empty( $state ) && isset( $state['total'] ) && $state['total'] > 0;
	}

	/**
	 * Get regeneration progress.
	 *
	 * @return array|null Progress data or null if not regenerating.
	 */
	public static function get_progress() {
		$instance = self::get();
		$state    = $instance->get_state();

		if ( empty( $state ) ) {
			return null;
		}

		return self::format_progress( $state );
	}

	/**
	 * Clear completion data.
	 */
	public static function clear_completion() {
		Helper::remove_notification( 'rank_math_link_genius_regenerate_started' );
		Helper::remove_notification( 'rank_math_link_genius_regenerate_complete' );

		$instance = self::get();
		$instance->clear_state();
	}

	/**
	 * Task to process posts.
	 *
	 * @param array $post_ids Array of post IDs to process.
	 * @return bool|array False to remove item from queue, array to retry.
	 */
	protected function task( $post_ids ) {
		if ( ! is_array( $post_ids ) ) {
			return false;
		}

		try {
			$processed_count = 0;

			// Batch fetch all posts at once instead of N queries.
			// Note: process_post_links() requires ID, post_content, post_status, and post_type.
			$posts_by_id = Batch_Helper::batch_fetch_posts( $post_ids, [ 'ID', 'post_content', 'post_status', 'post_type' ] );

			foreach ( $post_ids as $post_id ) {
				try {
					$post = $posts_by_id[ $post_id ] ?? null;
					if ( ! $post || empty( $post->post_content ) ) {
						// Count as processed even if empty - we still examined it.
						++$processed_count;
						continue;
					}

					// Use Link_Processor to process the post content.
					Link_Processor::process( $post_id, $post->post_content );

					++$processed_count;

					// TESTING: Add artificial delay if debug mode is enabled.
					if ( defined( 'RANK_MATH_LINK_GENIUS_DEBUG' ) && RANK_MATH_LINK_GENIUS_DEBUG ) {
						// Sleep for 2 seconds per post to make progress visible.
						sleep( 2 );
					}
				} catch ( \Exception $e ) {
					$this->handle_task_error( 'Regenerate', 'rank_math_link_genius_regenerate_errors', $post_id, $e->getMessage() );
					// Continue processing other posts in the batch.
					continue;
				}
			}

			// Batch update progress counter using trait method.
			if ( $processed_count > 0 ) {
				$this->increment_progress( $processed_count );
			}

			return false; // Successfully processed, remove from queue.

		} catch ( \Exception $e ) {
			// Critical error - log and retry.
			return $this->handle_task_error(
				$post_ids,
				'Regenerate',
				'rank_math_link_genius_regenerate_errors',
				$e->getMessage()
			);
		}
	}

	/**
	 * Complete processing.
	 *
	 * Override if applicable, but ensure that the below actions are
	 * performed, or, call parent::complete().
	 */
	protected function complete() {
		parent::complete();

		// Remove started notification.
		Helper::remove_notification( 'rank_math_link_genius_regenerate_started' );

		// Get counts for completion message from state.
		$state       = $this->get_state();
		$total_posts = $state['total'] ?? 0;
		$errors      = $state['errors'] ?? 0;

		// Mark operation as completed in state.
		$this->mark_completed();

		// Release process coordination locks.
		// This must happen here because WP_Background_Process::handle() calls
		// wp_die() after complete(), which terminates PHP and prevents the
		// finally block in Coordinated_Processor::handle() from executing.
		$operation = $this->get_operation_name();
		Process_Coordinator::mark_write_complete( $operation );
		Process_Coordinator::release_lock( $operation );

		// Cannot trigger background process from within another background process.
		// Must use wp_schedule_single_event to decouple from this process.
		if ( ! wp_next_scheduled( 'rank_math_link_genius_start_crawler' ) ) {
			wp_schedule_single_event( time(), 'rank_math_link_genius_start_crawler' );
		}
	}

	/**
	 * Get state option name for this processor.
	 *
	 * @return string
	 */
	protected function get_state_option_name() {
		return 'rank_math_regenerate_state';
	}

	/**
	 * Get operation name for coordination.
	 *
	 * @return string
	 */
	protected function get_operation_name() {
		return 'regenerate';
	}

	/**
	 * Truncate internal links tables.
	 */
	private function truncate_tables() {
		global $wpdb;

		// Temporarily disable foreign key checks to allow truncating parent table.
		DB::query( 'SET FOREIGN_KEY_CHECKS = 0' );

		// Truncate status table if it exists.
		if ( DB::check_table_exists( 'rank_math_link_genius_audit' ) ) {
			DB::query( "TRUNCATE TABLE {$wpdb->prefix}rank_math_link_genius_audit" );
		}

		// Truncate parent tables.
		DB::query( "TRUNCATE TABLE {$wpdb->prefix}rank_math_internal_links" );
		DB::query( "TRUNCATE TABLE {$wpdb->prefix}rank_math_internal_meta" );

		// Re-enable foreign key checks.
		DB::query( 'SET FOREIGN_KEY_CHECKS = 1' );

		// Delete all processed meta keys.
		delete_post_meta_by_key( 'rank_math_internal_links_processed' );

		// Clear cache.
		Query_Builder::invalidate_cache();
	}
}
