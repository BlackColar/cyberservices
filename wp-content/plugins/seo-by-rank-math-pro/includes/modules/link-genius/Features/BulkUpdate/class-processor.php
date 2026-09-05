<?php
/**
 * Bulk Update Processor for Link Genius.
 *
 * Handles background processing for bulk link updates.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius\Bulk_Update
 */

namespace RankMathPro\Link_Genius\Features\BulkUpdate;

use WP_Error;
use RankMath\Helper;
use RankMath\Helpers\DB;
use RankMathPro\Link_Genius\Data\Query_Builder;
use RankMathPro\Link_Genius\Data\Link_Updater;
use RankMathPro\Link_Genius\Background\Process_Coordinator;
use RankMathPro\Link_Genius\Background\Processor_Utils;
use RankMathPro\Link_Genius\Services\Batch_Helper;
use RankMathPro\Link_Genius\Services\Link_Processor;
use RankMathPro\Link_Genius\Services\Utils;

defined( 'ABSPATH' ) || exit;

/**
 * Processor class.
 *
 * Extends WP_Background_Process to handle bulk update operations.
 */
class Processor extends \WP_Background_Process {

	use \RankMathPro\Link_Genius\Background\Background_Processor_Error_Handler;
	use \RankMathPro\Link_Genius\Background\Progress_Helper;

	/**
	 * Action.
	 *
	 * @var string
	 */
	protected $action = 'link_genius_bulk_update';

	/**
	 * Get state option name for this processor.
	 *
	 * @return string
	 */
	protected function get_state_option_name() {
		return 'rank_math_bulk_update_current';
	}

	/**
	 * Main instance.
	 *
	 * Ensure only one instance is loaded or can be loaded.
	 *
	 * @return Processor
	 */
	public static function get() {
		static $instance;

		if ( is_null( $instance ) && ! ( $instance instanceof Processor ) ) {
			$instance = new Processor();
		}

		return $instance;
	}

	/**
	 * Get the chunk size for background processing.
	 *
	 * @return int Number of posts to process per chunk.
	 */
	public static function get_chunk_size() {
		return Processor_Utils::get_filtered_chunk_size(
			'rank_math/link_genius/bulk_update_chunk_size',
			10
		);
	}

	/**
	 * Start bulk update process.
	 *
	 * Site-wide single process - uses fixed 'current' identifier.
	 *
	 * @param array  $filters           Filters to apply when finding links.
	 * @param array  $update_config     Update configuration.
	 * @param array  $link_ids          Optional array of specific link IDs to update.
	 * @param bool   $is_rollback       Whether this is a rollback operation.
	 * @param string $rollback_batch_id Batch ID being rolled back (if rollback).
	 * @return array|WP_Error Process start details or error.
	 */
	public function start( $filters, $update_config, $link_ids = null, $is_rollback = false, $rollback_batch_id = null ) {
		global $wpdb;

		// Check if another update is already running using shared validation.
		$existing_progress = $this->get_state();
		$validation_result = Processor_Utils::validate_not_in_progress( $existing_progress, 'bulk update' );
		if ( is_wp_error( $validation_result ) ) {
			return $validation_result;
		}

		// Determine posts to process based on mode.
		if ( $is_rollback && $rollback_batch_id ) {
			// ROLLBACK MODE: Get posts from snapshots using shared utility.
			$snapshots = Processor_Utils::fetch_rollback_snapshots( $rollback_batch_id );

			if ( is_wp_error( $snapshots ) ) {
				return $snapshots;
			}

			// Calculate totals using shared utility.
			$totals      = Processor_Utils::calculate_snapshot_totals( $snapshots );
			$post_ids    = $totals['post_ids'];
			$total_posts = $totals['total_posts'];
			$total_links = $totals['total_links'];

			// For rollback, we don't need links_by_post since we restore from snapshots.
			$links_by_post       = [];
			$affected_posts_data = [];
		} else {
			// NORMAL UPDATE MODE: Get links from query.
			$query_args = $filters;
			if ( ! empty( $link_ids ) ) {
				$query_args['link_ids'] = $link_ids;
			}
			$query_args['per_page'] = 0; // Get all links.

			$all_links = Query_Builder::get_links( $query_args );
			if ( empty( $all_links ) ) {
				return new WP_Error(
					'no_links_found',
					__( 'No links found matching the specified filters.', 'rank-math-pro' )
				);
			}

			// Group links by post_id for efficient processing.
			$links_by_post = [];
			foreach ( $all_links as $link ) {
				$links_by_post[ $link->post_id ][] = $link;
			}

			$post_ids            = array_keys( $links_by_post );
			$total_posts         = count( $post_ids );
			$total_links         = count( $all_links );
			$affected_posts_data = [];
		}

		// Create batch history record ONLY if not a rollback.
		// For rollback, we'll update the existing batch status later.
		if ( ! $is_rollback ) {
			// Clean up any stale 'current' records from previous failed runs.
			$wpdb->delete(
				$wpdb->prefix . 'rank_math_link_genius_history',
				[ 'batch_id' => 'current' ],
				[ '%s' ]
			);
			$wpdb->delete(
				$wpdb->prefix . 'rank_math_link_genius_snapshots',
				[ 'batch_id' => 'current' ],
				[ '%s' ]
			);

			$wpdb->insert(
				$wpdb->prefix . 'rank_math_link_genius_history',
				[
					'batch_id'             => 'current',
					'source_type'          => 'bulk_update',
					'user_id'              => get_current_user_id(),
					'operation_type'       => $update_config['operation_type'],
					'filters'              => wp_json_encode( $filters ),
					'changes_summary'      => wp_json_encode(
						[
							'from_anchor' => $update_config['anchor_update']['search_value'] ?? '',
							'to_anchor'   => $update_config['anchor_update']['replace_value'] ?? '',
							'from_url'    => $update_config['url_update']['search_value'] ?? '',
							'to_url'      => $update_config['url_update']['replace_value'] ?? '',
						]
					),
					'affected_links_count' => $total_links,
					'affected_posts_count' => $total_posts,
					'status'               => 'processing',
					'created_at'           => current_time( 'mysql' ),
				],
				[ '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
			);
		}

		// Store only IDs and config, not entire link objects.
		// This reduces option size from 5-10 MB to ~50 KB.
		// Links will be refetched in task() using Query_Builder.
		$progress_data = [
			'batch_id'          => 'current',
			'total_posts'       => $total_posts,
			'total_links'       => $total_links,
			'processed_posts'   => 0,
			'processed_links'   => 0,
			'started_at'        => current_time( 'mysql' ),
			'update_config'     => $update_config,
			'filters'           => $filters, // Store filters to refetch links in task().
			'link_ids'          => $link_ids, // Store selected link IDs to maintain constraint in task().
			'current_post_id'   => null,
			'is_rollback'       => $is_rollback,
			'rollback_batch_id' => $rollback_batch_id,
			'affected_post_ids' => $post_ids, // Store post IDs for crawler queueing.
		];

		$this->set_state( $progress_data );

		// Queue chunks of posts.
		$chunk_size = self::get_chunk_size();
		$num_chunks = ceil( $total_posts / $chunk_size );

		for ( $i = 0; $i < $num_chunks; $i++ ) {
			$chunk_post_ids = array_slice( $post_ids, $i * $chunk_size, $chunk_size );

			$this->push_to_queue(
				[
					'post_ids' => $chunk_post_ids,
				]
			);
		}

		$this->save()->dispatch();

		return [
			'success'     => true,
			'batch_id'    => 'current',
			'total_posts' => $total_posts,
			'total_links' => $total_links,
			'message'     => __( 'Bulk update scheduled. Processing in background.', 'rank-math-pro' ),
		];
	}

	/**
	 * Handle cron healthcheck.
	 *
	 * Mark write operations as active for the entire bulk update process.
	 * This prevents the Link Status Crawler from running concurrently and causing lock contention.
	 *
	 * Only ONE Link Genius process (across all types) can run at any time.
	 */
	protected function handle() {
		if ( ! Process_Coordinator::acquire_lock( 'bulk_update' ) ) {
			// Another process is running, pause this one.
			// The queue will automatically retry later via cron.
			return;
		}

		// Mark as write-active for the entire bulk update process.
		Process_Coordinator::mark_write_active( 'bulk_update' );

		try {
			// Process all batches in the queue.
			parent::handle();
		} finally {
			// Mark as write-complete when all batches are done.
			Process_Coordinator::mark_write_complete( 'bulk_update' );
			Process_Coordinator::release_lock( 'bulk_update' );
		}
	}

	/**
	 * Task to perform - processes one chunk of posts.
	 *
	 * @param array $chunk_data Chunk data with post_ids.
	 * @return bool|array False to remove item from queue, array to retry.
	 */
	protected function task( $chunk_data ) {
		global $wpdb;

		try {
			$post_ids = $chunk_data['post_ids'];

			// Get progress metadata.
			$progress = $this->get_state();
			if ( empty( $progress ) ) {
				return false; // Job not found, remove from queue.
			}

			// Check if this is a rollback operation.
			$is_rollback       = ! empty( $progress['is_rollback'] );
			$rollback_batch_id = ! empty( $progress['rollback_batch_id'] ) ? $progress['rollback_batch_id'] : null;

			if ( $is_rollback && $rollback_batch_id ) {
				// ROLLBACK MODE: Restore posts from snapshots.
				// Batch fetch all snapshots at once instead of N queries.
				$snapshots = Batch_Helper::batch_fetch_snapshots( $rollback_batch_id, $post_ids );

				if ( empty( $snapshots ) ) {
					return false; // No snapshots found.
				}

				// Prepare content map and batch update posts.
				$content_map = [];
				$total_links = 0;

				foreach ( $post_ids as $post_id ) {
					$snapshot = $snapshots[ $post_id ] ?? null;
					if ( ! $snapshot ) {
						continue;
					}

					$content_map[ $post_id ] = $snapshot->original_content;

					// Count links (for progress tracking).
					$link_changes = json_decode( $snapshot->link_changes, true );
					$total_links += is_array( $link_changes ) ? count( $link_changes ) : 0;
				}

				if ( ! empty( $content_map ) ) {
					// Use DRY method for batch update.
					$result = $this->batch_update_post_content( $content_map );

					if ( false !== $result ) {
						// IMPORTANT: Update link tracking for all restored posts.
						$this->process_link_tracking( $content_map );

						// Update progress once after batch processing.
						$progress['processed_posts'] += count( $content_map );
						$progress['processed_links'] += $total_links;
						$progress['current_post_id']  = array_key_last( $content_map );
					}
				}
			} else {
				// NORMAL UPDATE MODE: Apply bulk updates.
				$update_config = $progress['update_config'];
				$filters       = $progress['filters'];
				$updater       = new Link_Updater();

				// Include selected link IDs in filters to maintain the constraint from start().
				$fetch_filters = $filters;
				if ( ! empty( $progress['link_ids'] ) ) {
					$fetch_filters['link_ids'] = $progress['link_ids'];
				}

				// Batch fetch links and posts for this chunk using helper methods.
				$links_by_post = Batch_Helper::batch_fetch_links_by_post( $post_ids, $fetch_filters );
				$posts_by_id   = Batch_Helper::batch_fetch_posts( $post_ids, [ 'ID', 'post_content' ] );

				// Collect snapshots and updated content for batch operations.
				$snapshots_data  = [];
				$posts_to_update = [];
				$now             = current_time( 'mysql' );

				// Process each post to prepare data.
				foreach ( $post_ids as $post_id ) {
					// Get post from batch-fetched array.
					$post = $posts_by_id[ $post_id ] ?? null;
					if ( ! $post || empty( $links_by_post[ $post_id ] ) ) {
						continue;
					}

					$links_to_update = $links_by_post[ $post_id ];

					// Collect snapshot data.
					$snapshots_data[] = [
						'batch_id'         => 'current',
						'post_id'          => $post_id,
						'original_content' => $post->post_content,
						'link_changes'     => wp_json_encode( Utils::prepare_link_changes( $links_to_update, $update_config ) ),
						'created_at'       => $now,
					];

					// Update content with multiple links.
					$new_content = $updater->update_multiple_links_in_content(
						$post->post_content,
						$links_to_update,
						$update_config
					);

					// Only save if content actually changed.
					if ( $new_content !== $post->post_content ) {
						$posts_to_update[ $post_id ] = [
							'content'     => $new_content,
							'links_count' => count( $links_to_update ),
						];
					}

					// Track progress (will be updated once after batch operations).
					++$progress['processed_posts'];
					$progress['processed_links'] += count( $links_to_update );
					$progress['current_post_id']  = $post_id;
				}

				// Batch insert all snapshots in a single query.
				if ( ! empty( $snapshots_data ) ) {
					Batch_Helper::batch_insert_snapshots( $snapshots_data );
				}

				// Batch update all posts using DRY method.
				if ( ! empty( $posts_to_update ) ) {
					// Prepare content map.
					$content_map = [];
					foreach ( $posts_to_update as $post_id => $data ) {
						$content_map[ $post_id ] = $data['content'];
					}

					// Use DRY method for batch update.
					$this->batch_update_post_content( $content_map );

					// IMPORTANT: Update link tracking for all updated posts.
					$this->process_link_tracking( $content_map );
				}
			}

			// Save updated progress.
			$this->set_state( $progress );

			// Clear object cache to free memory.
			wp_cache_flush_group( Query_Builder::CACHE_GROUP );

			return false; // Successfully processed, remove from queue.

		} catch ( \Exception $e ) {
			// Critical error - log and retry.
			return $this->handle_task_error(
				$chunk_data,
				'Bulk Update',
				'rank_math_bulk_update_errors',
				$e->getMessage()
			);
		}
	}

	/**
	 * Complete bulk update processing.
	 *
	 * Called when all chunks have been processed.
	 */
	protected function complete() {
		// Ensure write operations are marked complete.
		// This is a safety net in case handle()'s finally block didn't execute.
		Process_Coordinator::mark_write_complete( 'bulk_update' );

		// Release global lock when Bulk update is complete.
		Process_Coordinator::release_lock( 'bulk_update' );

		global $wpdb;

		// Only get the current batch progress option (not ALL options with LIKE).
		// The old LIKE query was scanning entire wp_options table (1+ minute on large sites).
		$progress = $this->get_state();

		if ( ! empty( $progress ) ) {

			// Check if this is a rollback operation.
			$is_rollback       = ! empty( $progress['is_rollback'] );
			$rollback_batch_id = ! empty( $progress['rollback_batch_id'] ) ? $progress['rollback_batch_id'] : null;

			if ( $is_rollback && $rollback_batch_id ) {
				// For rollback: Use shared completion logic.
				Processor_Utils::complete_operation( $rollback_batch_id, 'bulk_update', true );

				// Invalidate cache.
				Query_Builder::invalidate_cache();
			} else {
				// Regular bulk update: Generate unique batch ID and create history.
				$completed_batch_id = 'batch_' . time() . '_' . wp_generate_password( 8, false );

				// Update history: rename batch_id from 'current' to unique ID.
				$wpdb->update(
					$wpdb->prefix . 'rank_math_link_genius_history',
					[ 'batch_id' => $completed_batch_id ],
					[ 'batch_id' => 'current' ],
					[ '%s' ],
					[ '%s' ]
				);

				// Also update snapshots to use the new batch_id.
				$wpdb->update(
					$wpdb->prefix . 'rank_math_link_genius_snapshots',
					[ 'batch_id' => $completed_batch_id ],
					[ 'batch_id' => 'current' ],
					[ '%s' ],
					[ '%s' ]
				);

				// Use shared completion logic.
				Processor_Utils::complete_operation( $completed_batch_id, 'bulk_update', false );

				// Invalidate cache ONCE after all updates.
				Query_Builder::invalidate_cache();
			}
		}

		// Mark as completed in state (don't clear yet - user needs to see completion).
		$this->mark_completed();

		parent::complete();

		// Queue crawler for only the affected links from bulk update.
		// Get all links from the posts that were updated and queue them for status checking.
		if ( $progress && is_array( $progress ) && ! empty( $progress['affected_post_ids'] ) ) {
			$this->queue_affected_links_for_crawler( $progress['affected_post_ids'] );
		}
	}

	/**
	 * Queue links from affected posts for crawler.
	 *
	 * This ensures we only check links from posts that were actually updated,
	 * not all unchecked links in the entire site.
	 *
	 * @param array $post_ids Array of post IDs that were affected by bulk update.
	 */
	private function queue_affected_links_for_crawler( $post_ids ) {
		if ( empty( $post_ids ) ) {
			return;
		}

		global $wpdb;

		// Get all links from the affected posts.
		$placeholders = implode( ',', array_fill( 0, count( $post_ids ), '%d' ) );

		$links = DB::get_results(
			$wpdb->prepare(
				"SELECT url, url_hash, type
				FROM {$wpdb->prefix}rank_math_internal_links
				WHERE post_id IN ({$placeholders})
				AND url_hash IS NOT NULL
				GROUP BY url_hash", // phpcs:ignore -- WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, WordPress.DB.PreparedSQLPlaceholders.UnquotedComplexPlaceholder
				...$post_ids
			),
			ARRAY_A
		);

		if ( ! empty( $links ) ) {
			// Queue these specific links for status checking.
			\RankMathPro\Link_Genius\Background\Link_Status_Crawler::queue_links_batch( $links );
		}
	}

	/**
	 * Get progress for the current batch.
	 *
	 * @return array|false Progress data or false if not found.
	 */
	public static function get_progress() {
		$instance = self::get();
		$state    = $instance->get_state();

		if ( empty( $state ) ) {
			return false;
		}

		$total     = $state['total_posts'] ?? 0;
		$processed = $state['processed_posts'] ?? 0;

		// Create normalized state for is_operation_active() which expects 'total' and 'processed' keys.
		$normalized_state = array_merge(
			$state,
			[
				'total'     => $total,
				'processed' => $processed,
			]
		);
		$is_active        = self::is_operation_active( $normalized_state );

		return [
			'active'          => $is_active,
			'batch_id'        => 'current',
			'total'           => $total,
			'processed'       => $processed,
			'total_links'     => $state['total_links'] ?? 0,
			'processed_links' => $state['processed_links'] ?? 0,
			'current_post_id' => $state['current_post_id'] ?? null,
			'percent'         => $total > 0 ? round( ( $processed / $total ) * 100 ) : 0,
			'started_at'      => $state['started_at'] ?? null,
			'completed_at'    => $state['completed_at'] ?? null,
		];
	}

	/**
	 * Cancel a batch.
	 *
	 * @return bool True on success, false on failure.
	 */
	public function cancel() {
		global $wpdb;

		// Update history status.
		$wpdb->update(
			$wpdb->prefix . 'rank_math_link_genius_history',
			[
				'status'        => 'failed',
				'error_message' => __( 'Cancelled by user', 'rank-math-pro' ),
				'completed_at'  => current_time( 'mysql' ),
			],
			[ 'batch_id' => 'current' ],
			[ '%s', '%s', '%s' ],
			[ '%s' ]
		);

		// Clear the cron event.
		$this->clear_scheduled_event();

		// Delete all queued batches.
		$this->delete_batches_from_db();

		// Unlock the parent process lock.
		$this->unlock_process();

		// Release coordination locks.
		Process_Coordinator::mark_write_complete( 'bulk_update' );
		Process_Coordinator::release_lock( 'bulk_update' );

		// Clean up progress state.
		$this->clear_state();

		return true;
	}

	/**
	 * Clear completion data.
	 *
	 * Called when user clicks the Close button on completed progress.
	 */
	public static function clear_completion() {
		$instance = self::get();
		$instance->clear_state();
		delete_option( 'rank_math_bulk_update_errors' );
	}

	/**
	 * Batch update post content using CASE WHEN statement.
	 *
	 * This method performs a single UPDATE query for multiple posts instead of N individual queries.
	 * DRY method to avoid code duplication between rollback and normal update modes.
	 *
	 * @param array $content_map Associative array of post_id => content.
	 * @return int|false         Number of rows updated or false on error.
	 */
	private function batch_update_post_content( $content_map ) {
		global $wpdb;

		if ( empty( $content_map ) ) {
			return 0;
		}

		$post_ids   = array_keys( $content_map );
		$case_parts = [];

		// Build CASE WHEN statement.
		foreach ( $content_map as $post_id => $content ) {
			$case_parts[] = $wpdb->prepare( 'WHEN %d THEN %s', $post_id, $content );
		}

		$ids_sql  = implode( ',', $post_ids );
		$case_sql = implode( ' ', $case_parts );
		$now      = current_time( 'mysql' );
		$now_gmt  = current_time( 'mysql', 1 );

		// Execute single batch UPDATE.
		$result = DB::query(
			"UPDATE {$wpdb->posts}
			SET post_content = CASE ID {$case_sql} END,
				post_modified = '{$now}',
				post_modified_gmt = '{$now_gmt}'
			WHERE ID IN ({$ids_sql})"
		);

		return $result;
	}

	/**
	 * Process link tracking for multiple posts.
	 *
	 * This method handles the loop of cleaning post cache and updating
	 * link tracking for each post. Extracted to follow DRY principle.
	 *
	 * @param array $content_map Associative array of post_id => content.
	 */
	private function process_link_tracking( $content_map ) {
		foreach ( $content_map as $post_id => $content ) {
			Link_Processor::process( $post_id, $content );
		}
	}
}
