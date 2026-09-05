<?php
/**
 * Consolidated background process for bulk link modifications.
 *
 * Handles delete, mark_safe, and restore operations in a single processor.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius\Background;

use WP_Error;
use RankMath\Helpers\DB;
use RankMathPro\Link_Genius\Services\Batch_Helper;
use RankMathPro\Link_Genius\Data\Query_Builder;
use RankMathPro\Link_Genius\Data\Link_Updater;
use RankMathPro\Link_Genius\Services\Link_Processor;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk_Link_Modifier class.
 *
 * Consolidated processor for bulk link operations:
 * - delete: Remove links from content
 * - mark_safe: Mark/unmark broken links as safe
 * - restore: Restore deleted links from snapshots
 */
class Bulk_Link_Modifier extends \WP_Background_Process {

	use Background_Processor_Error_Handler;
	use Progress_Helper;

	/**
	 * Action identifier.
	 *
	 * @var string
	 */
	protected $action = 'link_genius_bulk_modifier';

	/**
	 * Operation type for current batch.
	 *
	 * @var string One of: 'delete', 'mark_safe', 'restore'.
	 */
	private $operation_type;

	/**
	 * In-memory counter for processed items (to batch DB updates).
	 *
	 * @var int
	 */
	private $processed_batch = 0;

	/**
	 * In-memory counter for failed items (to batch DB updates).
	 *
	 * @var int
	 */
	private $failed_batch = 0;

	/**
	 * Main instance.
	 *
	 * Ensure only one instance is loaded or can be loaded.
	 *
	 * @return Bulk_Link_Modifier
	 */
	public static function get() {
		static $instance;

		if ( is_null( $instance ) && ! ( $instance instanceof Bulk_Link_Modifier ) ) {
			$instance = new Bulk_Link_Modifier();
		}

		return $instance;
	}

	/**
	 * Start bulk operation.
	 *
	 * @param string $operation Operation type: 'delete', 'mark_safe', or 'restore'.
	 * @param array  $params    Operation-specific parameters.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function start( $operation, $params = [] ) {
		// Validate operation type.
		if ( ! in_array( $operation, [ 'delete', 'mark_safe', 'restore' ], true ) ) {
			return new WP_Error(
				'invalid_operation',
				__( 'Invalid operation type.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		// Route to specific start method based on operation.
		switch ( $operation ) {
			case 'delete':
				return $this->start_delete( $params );
			case 'mark_safe':
				return $this->start_mark_safe( $params );
			case 'restore':
				return $this->start_restore( $params );
		}

		return false;
	}

	/**
	 * Check if bulk operation is currently active.
	 *
	 * @return bool True if bulk operation is active.
	 */
	public function is_active() {
		return self::is_operation_active( $this->get_state() );
	}

	/**
	 * Get bulk operation progress.
	 *
	 * @param string $requested_operation Optional. Specific operation to get progress for (delete, mark_safe, restore).
	 *                                    If provided, only returns progress if it matches the current/completed operation.
	 * @return array|null Progress data or null if not active/completed or doesn't match requested operation.
	 */
	public static function get_progress( $requested_operation = null ) {
		$instance = self::get();
		$state    = $instance->get_state();

		if ( empty( $state ) ) {
			return null;
		}

		$operation = $state['operation'] ?? '';

		// If a specific operation was requested, only return if it matches.
		if ( $requested_operation && $operation !== $requested_operation ) {
			return null;
		}

		// Format progress using helper trait.
		$progress = self::format_progress( $state );

		// Add operation-specific fields.
		$progress['operation'] = $operation;

		if ( isset( $state['batch_id'] ) ) {
			$progress['batch_id'] = $state['batch_id'];
		}

		return $progress;
	}

	/**
	 * Cancel bulk operation.
	 */
	public function cancel() {
		global $wpdb;

		// Get state before clearing.
		$state     = $this->get_state();
		$operation = $state['operation'] ?? 'modifier';
		$batch_id  = $state['batch_id'] ?? '';

		// Stop the background process by clearing the cron event.
		$this->clear_scheduled_event();

		// Delete all queued batches (memory efficient).
		$this->delete_batches_from_db();

		// Unlock the parent process lock (from WP_Background_Process).
		$this->unlock_process();

		// Release locks.
		Process_Coordinator::mark_write_complete( 'bulk_' . $operation );
		Process_Coordinator::release_lock( 'bulk_' . $operation );

		// Update history record to mark as cancelled (for delete operations).
		if ( ! empty( $batch_id ) ) {
			$wpdb->update(
				"{$wpdb->prefix}rank_math_link_genius_history",
				[
					'status'        => 'failed',
					'error_message' => __( 'Cancelled by user', 'rank-math-pro' ),
					'completed_at'  => current_time( 'mysql' ),
				],
				[ 'batch_id' => $batch_id ],
				[ '%s', '%s', '%s' ],
				[ '%s' ]
			);
		}

		// Clear consolidated state.
		$this->clear_state();
	}

	/**
	 * Clear completion data.
	 */
	public static function clear_completion() {
		$instance = self::get();
		$instance->clear_state();
	}

	/**
	 * Get state option name for this processor.
	 *
	 * @return string
	 */
	protected function get_state_option_name() {
		return 'rank_math_bulk_modifier_state';
	}

	/**
	 * Handle cron healthcheck.
	 *
	 * Acquires lock and marks write operations as active to coordinate
	 * with other background processes (prevents concurrent operations).
	 */
	protected function handle() {
		$state     = $this->get_state();
		$operation = $state['operation'] ?? 'modifier';

		if ( ! Process_Coordinator::acquire_lock( 'bulk_' . $operation ) ) {
			// Another process is running, pause this one.
			return;
		}

		// Mark as write-active for the entire bulk operation process.
		Process_Coordinator::mark_write_active( 'bulk_' . $operation );

		try {
			// Process all batches in the queue.
			parent::handle();
		} finally {
			// Mark as write-complete when all batches are done.
			Process_Coordinator::mark_write_complete( 'bulk_' . $operation );
			Process_Coordinator::release_lock( 'bulk_' . $operation );
		}
	}

	/**
	 * Task to process - handles delete, mark_safe, or restore based on operation_type.
	 *
	 * @param array $item_data Task data with operation_type and operation-specific fields.
	 * @return bool|array False to remove item from queue, array to retry.
	 */
	protected function task( $item_data ) {
		try {
			$operation_type = $item_data['operation_type'] ?? '';

			// Route to specific task method based on operation.
			switch ( $operation_type ) {
				case 'delete':
					return $this->task_delete( $item_data );
				case 'mark_safe':
					return $this->task_mark_safe( $item_data );
				case 'restore':
					return $this->task_restore( $item_data );
			}

			// Unknown operation type.
			$state     = $this->get_state();
			$operation = $state['operation'] ?? 'modifier';
			$this->log_error(
				'Bulk ' . ucfirst( $operation ),
				'rank_math_bulk_modifier_errors',
				'unknown_operation',
				"Unknown operation type: {$operation_type}"
			);
			$this->increment_failed();
			return false;

		} catch ( \Exception $e ) {
			// Critical error - log and retry.
			$state     = $this->get_state();
			$operation = $state['operation'] ?? 'modifier';
			$result    = $this->handle_task_error(
				$item_data,
				'Bulk ' . ucfirst( $operation ),
				'rank_math_bulk_modifier_errors',
				$e->getMessage()
			);

			if ( false === $result ) {
				$this->increment_failed();
			}

			return $result;
		}
	}

	/**
	 * Complete processing.
	 *
	 * Called when queue is empty.
	 */
	protected function complete() {
		global $wpdb;

		// Flush any remaining progress updates before completion.
		$this->flush_progress();

		// Get state before completing.
		$state     = $this->get_state();
		$operation = $state['operation'] ?? 'modifier';
		$batch_id  = $state['batch_id'] ?? '';

		// Ensure write operations are marked complete.
		Process_Coordinator::mark_write_complete( 'bulk_' . $operation );

		// Release global lock when operation is complete.
		Process_Coordinator::release_lock( 'bulk_' . $operation );

		parent::complete();

		// Operation-specific completion logic.
		if ( 'delete' === $operation && ! empty( $batch_id ) ) {
			// Update history record to mark as completed.
			$wpdb->update(
				"{$wpdb->prefix}rank_math_link_genius_history",
				[
					'status'       => 'completed',
					'completed_at' => current_time( 'mysql' ),
				],
				[ 'batch_id' => $batch_id ],
				[ '%s', '%s' ],
				[ '%s' ]
			);
		} elseif ( 'restore' === $operation && ! empty( $batch_id ) ) {
			// Update history record to mark as rolled_back.
			$wpdb->update(
				"{$wpdb->prefix}rank_math_link_genius_history",
				[
					'status'       => 'rolled_back',
					'completed_at' => current_time( 'mysql' ),
				],
				[ 'batch_id' => $batch_id ],
				[ '%s', '%s' ],
				[ '%s' ]
			);

			// Delete snapshots after successful restore - they're no longer needed.
			$wpdb->delete(
				"{$wpdb->prefix}rank_math_link_genius_snapshots",
				[ 'batch_id' => $batch_id ],
				[ '%s' ]
			);
		}

		// Mark operation as completed in state.
		$this->mark_completed();

		// Invalidate query cache.
		Query_Builder::invalidate_cache();
	}

	/**
	 * Prepare query arguments for fetching links.
	 *
	 * Removes UI-specific fields and merges filters with additional arguments.
	 *
	 * @param array $filters         Filter parameters.
	 * @param array $link_ids        Optional specific link IDs.
	 * @param array $additional_args Optional additional query arguments.
	 * @return array Prepared query arguments for Query_Builder.
	 */
	private function prepare_query_args_for_fetch( $filters, $link_ids = [], $additional_args = [] ) {
		$query_args = $filters;

		// Remove UI-specific fields that Query_Builder doesn't recognize.
		unset( $query_args['filter'] );
		unset( $query_args['page'] );
		unset( $query_args['per_page'] );

		// Add any additional query arguments.
		if ( ! empty( $additional_args ) ) {
			$query_args = array_merge( $query_args, $additional_args );
		}

		// Add specific link IDs if provided.
		if ( ! empty( $link_ids ) ) {
			$query_args['link_ids'] = $link_ids;
		}

		return $query_args;
	}

	/**
	 * Start bulk delete operation.
	 *
	 * Uses cursor-based pagination to fetch links in chunks, preventing memory exhaustion
	 * when deleting large numbers of links (e.g., 500K+ links).
	 *
	 * @param array $params Parameters: filters, link_ids.
	 * @return bool|WP_Error
	 */
	private function start_delete( $params ) {
		global $wpdb;

		$filters  = $params['filters'] ?? [];
		$link_ids = $params['link_ids'] ?? [];

		// Get total count first (lightweight query).
		$query_args  = $this->prepare_query_args_for_fetch( $filters, $link_ids );
		$total_links = Query_Builder::get_links_count( $query_args );

		if ( $total_links === 0 ) {
			return false;
		}

		// Generate unique batch ID for this delete operation.
		$batch_id = 'delete_' . time() . '_' . wp_generate_password( 8, false );

		// Create history record for rollback support.
		// Note: affected_posts_count will be updated during processing.
		$wpdb->insert(
			"{$wpdb->prefix}rank_math_link_genius_history",
			[
				'batch_id'             => $batch_id,
				'source_type'          => 'delete',
				'user_id'              => get_current_user_id(),
				'operation_type'       => 'delete',
				'filters'              => wp_json_encode( $filters ),
				'changes_summary'      => wp_json_encode( [ 'action' => 'delete_links' ] ),
				'affected_links_count' => $total_links,
				'affected_posts_count' => 0, // Updated incrementally.
				'status'               => 'processing',
				'created_at'           => current_time( 'mysql' ),
			],
			[ '%s', '%s', '%d', '%s', '%s', '%s', '%d', '%d', '%s', '%s' ]
		);

		// Initialize progress tracking (track by number of links).
		$this->init_progress_state(
			$total_links,
			[
				'operation' => 'delete',
				'batch_id'  => $batch_id,
			]
		);

		// Fetch and queue links in chunks using cursor-based pagination.
		// This prevents loading all links into memory at once.
		/**
		 * Filter: Number of links to fetch per cursor iteration.
		 *
		 * Larger values = fewer DB queries but more memory.
		 * Smaller values = more DB queries but less memory.
		 *
		 * @param int $fetch_chunk_size Number of links per fetch. Default 1000.
		 */
		$fetch_chunk_size = (int) apply_filters( 'rank_math/link_genius/bulk_delete_fetch_chunk_size', 1000 );

		/**
		 * Filter: Number of posts to process per background job.
		 *
		 * @param int $process_chunk_size Number of posts per batch. Default 10.
		 */
		$process_chunk_size = (int) apply_filters( 'rank_math/link_genius/bulk_delete_chunk_size', 10 );

		$cursor = 0; // Start from beginning.

		while ( true ) {
			// Fetch chunk of links using cursor.
			$chunk_args = $this->prepare_query_args_for_fetch(
				$filters,
				$link_ids,
				[
					'per_page'        => $fetch_chunk_size,
					'cursor_after_id' => $cursor,
					'orderby'         => 'id',
					'order'           => 'ASC',
				]
			);

			$chunk_links = Query_Builder::get_links( $chunk_args );

			if ( empty( $chunk_links ) ) {
				break; // No more links.
			}

			// Group links by post_id for efficient batch processing.
			$links_by_post = [];
			foreach ( $chunk_links as $link ) {
				$links_by_post[ $link->post_id ][] = $link;
			}

			// Queue posts in smaller chunks for background processing.
			$post_ids    = array_keys( $links_by_post );
			$post_chunks = array_chunk( $post_ids, $process_chunk_size, true );

			foreach ( $post_chunks as $chunk_post_ids ) {
				// Prepare links data for this chunk.
				$chunk_links_data = [];
				foreach ( $chunk_post_ids as $post_id ) {
					$chunk_links_data[ $post_id ] = $links_by_post[ $post_id ];
				}

				$this->push_to_queue(
					[
						'operation_type' => 'delete',
						'post_ids'       => $chunk_post_ids,
						'links_by_post'  => $chunk_links_data,
					]
				);
			}

			// Update cursor to last link ID in this chunk.
			$last_link = end( $chunk_links );
			$cursor    = $last_link->id;
		}

		// Save queue and dispatch for processing.
		$this->save()->dispatch();

		return true;
	}

	/**
	 * Start bulk mark safe operation.
	 *
	 * Uses cursor-based pagination to fetch links in chunks, preventing memory exhaustion
	 * when marking large numbers of links as safe (e.g., 100K+ broken links).
	 *
	 * @param array $params Parameters: filters, link_ids, reason, is_marked_safe.
	 * @return bool|WP_Error
	 */
	private function start_mark_safe( $params ) {
		$filters        = $params['filters'] ?? [];
		$link_ids       = $params['link_ids'] ?? [];
		$reason         = $params['reason'] ?? '';
		$is_marked_safe = $params['is_marked_safe'] ?? 1;

		// Build additional query args for mark_safe operation.
		$additional_args = [];
		if ( 1 === (int) $is_marked_safe ) {
			$additional_args['is_broken'] = 1;
		}

		// Get total count first (lightweight query).
		$query_args  = $this->prepare_query_args_for_fetch( $filters, $link_ids, $additional_args );
		$total_links = Query_Builder::get_links_count( $query_args );

		if ( $total_links === 0 ) {
			return false;
		}

		// Initialize progress tracking.
		$this->init_progress_state(
			$total_links,
			[
				'operation' => 'mark_safe',
				'config'    => [
					'reason'         => $reason,
					'is_marked_safe' => (int) $is_marked_safe,
				],
			]
		);

		// Fetch and queue links in chunks using cursor-based pagination.
		/**
		 * Filter: Number of links to fetch per cursor iteration.
		 *
		 * @param int $fetch_chunk_size Number of links per fetch. Default 1000.
		 */
		$fetch_chunk_size = (int) apply_filters( 'rank_math/link_genius/bulk_mark_safe_fetch_chunk_size', 1000 );

		/**
		 * Filter: Number of links to process per background job.
		 *
		 * @param int $process_chunk_size Number of links per batch. Default 50.
		 */
		$process_chunk_size = (int) apply_filters( 'rank_math/link_genius/bulk_mark_safe_chunk_size', 50 );

		$cursor = 0; // Start from beginning.

		while ( true ) {
			// Fetch chunk of links using cursor.
			$chunk_args = $this->prepare_query_args_for_fetch(
				$filters,
				$link_ids,
				array_merge(
					$additional_args,
					[
						'per_page'        => $fetch_chunk_size,
						'cursor_after_id' => $cursor,
						'orderby'         => 'id',
						'order'           => 'ASC',
					]
				)
			);

			$chunk_links = Query_Builder::get_links( $chunk_args );

			if ( empty( $chunk_links ) ) {
				break; // No more links.
			}

			// Extract link IDs from chunk.
			$link_id_array = array_column( (array) $chunk_links, 'id' );

			// Queue link IDs in smaller chunks for background processing.
			$link_chunks = array_chunk( $link_id_array, $process_chunk_size );

			foreach ( $link_chunks as $chunk_link_ids ) {
				$this->push_to_queue(
					[
						'operation_type' => 'mark_safe',
						'link_ids'       => $chunk_link_ids,
					]
				);
			}

			// Update cursor to last link ID in this chunk.
			$last_link = end( $chunk_links );
			$cursor    = $last_link->id;
		}

		// Save queue and dispatch for processing.
		$this->save()->dispatch();

		return true;
	}

	/**
	 * Start bulk restore operation.
	 *
	 * @param array $params Parameters: batch_id.
	 * @return bool|WP_Error
	 */
	private function start_restore( $params ) {
		global $wpdb;

		$batch_id = $params['batch_id'] ?? '';

		if ( empty( $batch_id ) ) {
			return new WP_Error(
				'missing_batch_id',
				__( 'Batch ID is required for restore operation.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		// Verify batch exists and is a delete operation.
		$batch = DB::get_row(
			$wpdb->prepare(
				"SELECT id, source_type, status, affected_posts_count FROM {$wpdb->prefix}rank_math_link_genius_history WHERE batch_id = %s",
				$batch_id
			)
		);

		if ( ! $batch ) {
			return new WP_Error(
				'batch_not_found',
				__( 'Batch not found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		if ( 'delete' !== $batch->source_type ) {
			return new WP_Error(
				'invalid_batch_type',
				__( 'This batch is not a delete operation.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		if ( 'completed' !== $batch->status ) {
			return new WP_Error(
				'invalid_batch_status',
				__( 'Only completed delete operations can be restored.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		// Get post IDs for this batch.
		$post_ids = DB::get_col(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->prefix}rank_math_link_genius_snapshots WHERE batch_id = %s",
				$batch_id
			)
		);

		if ( empty( $post_ids ) ) {
			return new WP_Error(
				'no_snapshots_found',
				__( 'No snapshots found for this batch. Restore not possible.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		// Initialize progress tracking.
		$this->init_progress_state(
			count( $post_ids ),
			[
				'operation' => 'restore',
				'batch_id'  => $batch_id,
			]
		);

		// Queue post IDs in chunks for batch processing.
		/**
		 * Filter: Allow developers to change the restore chunk size.
		 *
		 * @param int $chunk_size Number of posts per batch. Default 10.
		 */
		$chunk_size = (int) apply_filters( 'rank_math/link_genius/bulk_restore_chunk_size', 10 );
		$chunks     = array_chunk( $post_ids, $chunk_size );

		foreach ( $chunks as $chunk_post_ids ) {
			$this->push_to_queue(
				[
					'operation_type' => 'restore',
					'post_ids'       => $chunk_post_ids,
				]
			);
		}

		// Save queue and dispatch for processing.
		$this->save()->dispatch();

		return true;
	}

	/**
	 * Process delete operation for a chunk of posts.
	 *
	 * @param array $chunk_data Chunk data with post_ids and links_by_post.
	 * @return bool False to remove from queue.
	 */
	private function task_delete( $chunk_data ) {
		global $wpdb;

		$post_ids      = $chunk_data['post_ids'] ?? [];
		$links_by_post = $chunk_data['links_by_post'] ?? [];

		if ( empty( $post_ids ) || empty( $links_by_post ) ) {
			return false;
		}

		// Batch fetch all posts in this chunk.
		$posts_by_id = Batch_Helper::batch_fetch_posts( $post_ids, [ 'ID', 'post_content' ] );

		// Get the batch ID for this delete operation.
		$state    = $this->get_state();
		$batch_id = $state['batch_id'] ?? 'delete_current';

		// Fetch existing snapshots for this batch and chunk posts.
		$existing_snapshots = Batch_Helper::batch_fetch_snapshots( $batch_id, $post_ids );

		// Process each post in the chunk.
		$snapshots_to_insert = [];
		$snapshots_to_update = [];
		$posts_to_update     = [];

		foreach ( $post_ids as $post_id ) {
			$post  = $posts_by_id[ $post_id ] ?? null;
			$links = $links_by_post[ $post_id ] ?? [];

			if ( ! $post || empty( $links ) ) {
				// Count failed links for this post.
				$this->increment_failed( count( $links ) );
				continue;
			}

			// Track link changes for snapshot.
			$link_changes    = [];
			$current_content = $post->post_content;
			$updater         = new Link_Updater();

			// Process all links for this post.
			foreach ( $links as $link ) {
				// Validate link data.
				if ( empty( $link->url ) || ( empty( $link->anchor_text ) && 'IMAGE' !== $link->anchor_type ) ) {
					$this->log_error( 'Bulk Delete', 'rank_math_bulk_modifier_errors', $link->id, 'Invalid link data' );
					$this->increment_failed();
					continue;
				}

				// Build delete pattern.
				$link_data = [
					'link_id'     => $link->id,
					'post_id'     => $link->post_id,
					'url'         => $link->url,
					'anchor_text' => $link->anchor_text,
					'anchor_type' => $link->anchor_type,
					'is_nofollow' => $link->is_nofollow,
				];
				$pattern   = $updater->build_delete_pattern( (object) $link_data, $current_content );

				if ( ! $pattern ) {
					// Pattern not found in content.
					$this->log_error( 'Bulk Delete', 'rank_math_bulk_modifier_errors', $link->id, 'Pattern not found in content' );
					$this->increment_failed();
					continue;
				}

				// Remove the link from content.
				$new_content = preg_replace_callback(
					$pattern,
					function ( $matches ) {
						// Return the captured inner content (anchor text or img tag).
						return $matches[1];
					},
					$current_content,
					1 // Only replace the first match.
				);

				if ( $new_content !== $current_content ) {
					$link_changes[]  = [
						'link_id'    => $link->id,
						'old_url'    => $link->url,
						'old_anchor' => $link->anchor_text,
						'action'     => 'delete',
					];
					$current_content = $new_content;
					$this->increment_processed();
				} else {
					$this->increment_failed();
				}
			}

			// If content changed, prepare for batch update.
			if ( ! empty( $link_changes ) && $current_content !== $post->post_content ) {
				$posts_to_update[ $post_id ] = $current_content;

				// Prepare snapshot data.
				if ( isset( $existing_snapshots[ $post_id ] ) ) {
					// Append to existing snapshot.
					$existing_changes = json_decode( $existing_snapshots[ $post_id ]->link_changes, true );
					$existing_changes = is_array( $existing_changes ) ? $existing_changes : [];
					$merged_changes   = array_merge( $existing_changes, $link_changes );

					$snapshots_to_update[ $post_id ] = wp_json_encode( $merged_changes );
				} else {
					// Create new snapshot.
					$snapshots_to_insert[] = [
						'batch_id'         => $batch_id,
						'post_id'          => $post_id,
						'original_content' => $post->post_content,
						'link_changes'     => wp_json_encode( $link_changes ),
						'created_at'       => current_time( 'mysql' ),
					];
				}
			}
		}

		// Batch insert new snapshots.
		if ( ! empty( $snapshots_to_insert ) ) {
			Batch_Helper::batch_insert_snapshots( $snapshots_to_insert );
		}

		// Batch update existing snapshots.
		if ( ! empty( $snapshots_to_update ) ) {
			$this->batch_update_snapshots( $batch_id, $snapshots_to_update );
		}

		// Batch update post content using Batch_Helper.
		if ( ! empty( $posts_to_update ) ) {
			Batch_Helper::update_post_content( $posts_to_update );
		}

		return false; // Remove from queue.
	}

	/**
	 * Process mark_safe operation for a chunk of links.
	 *
	 * @param array $chunk_data Chunk data with link_ids.
	 * @return bool False to remove from queue.
	 */
	private function task_mark_safe( $chunk_data ) {
		global $wpdb;

		$link_ids = $chunk_data['link_ids'] ?? [];

		if ( empty( $link_ids ) ) {
			return false;
		}

		$status_table = $wpdb->prefix . 'rank_math_link_genius_audit';
		$state        = $this->get_state();
		$config       = $state['config'] ?? [];

		if ( empty( $config ) ) {
			$this->increment_failed( count( $link_ids ) );
			return false;
		}

		$is_marked_safe = $config['is_marked_safe'];
		$reason         = $config['reason'];

		// Find existing status records.
		$placeholders = Batch_Helper::generate_placeholders( $link_ids, '%d' );
		$query        = "SELECT link_id FROM `{$status_table}` WHERE link_id IN ({$placeholders})";

		$existing_link_ids = DB::get_col( $wpdb->prepare( $query, ...$link_ids ) );
		$existing_link_ids = array_flip( $existing_link_ids );

		// Prepare batch insert/update data.
		$insert_values     = [];
		$insert_formats    = [];
		$update_case_whens = [];

		$error_message = $is_marked_safe
			? ( $reason ? 'Marked as safe: ' . $reason : 'Marked as safe by user' )
			: null;

		foreach ( $link_ids as $link_id ) {
			if ( isset( $existing_link_ids[ $link_id ] ) ) {
				// Prepare for batch update using CASE WHEN.
				$update_case_whens[] = $wpdb->prepare( 'WHEN %d THEN %d', $link_id, $is_marked_safe ? 1 : 0 );
			} else {
				// Prepare for batch insert.
				$insert_formats[] = '(%d, %d, %s)';
				$insert_values[]  = $link_id;
				$insert_values[]  = $is_marked_safe ? 1 : 0;
				$insert_values[]  = $error_message;
			}
		}

		// Execute batch update for existing records.
		if ( ! empty( $update_case_whens ) ) {
			$case_when_safe = implode( ' ', $update_case_whens );
			$where_ids      = implode( ',', array_map( 'intval', array_keys( $existing_link_ids ) ) );

			$error_clause = $is_marked_safe
				? $wpdb->prepare( 'last_error_message = %s', $error_message )
				: 'last_error_message = NULL';

			$updated = DB::query(
				"UPDATE `{$status_table}`
				SET is_marked_safe = CASE link_id {$case_when_safe} END,
					{$error_clause}
				WHERE link_id IN ({$where_ids})"
			);

			if ( false !== $updated ) {
				$this->processed_batch += count( $update_case_whens );
			} else {
				$this->failed_batch += count( $update_case_whens );
			}
		}

		// Execute batch insert for new records.
		if ( ! empty( $insert_values ) ) {
			$sql = "INSERT INTO `{$status_table}` (link_id, is_marked_safe, last_error_message)
					VALUES " . implode( ', ', $insert_formats );

			$inserted = $wpdb->query( $wpdb->prepare( $sql, ...$insert_values ) );

			if ( false !== $inserted ) {
				$this->processed_batch += count( $insert_formats );
			} else {
				$this->failed_batch += count( $insert_formats );
			}
		}

		// Flush progress if needed.
		$this->maybe_flush_progress();

		return false; // Remove from queue.
	}

	/**
	 * Process restore operation for a chunk of posts.
	 *
	 * @param array $chunk_data Chunk data with post_ids.
	 * @return bool False to remove from queue.
	 */
	private function task_restore( $chunk_data ) {
		global $wpdb;

		$post_ids = $chunk_data['post_ids'] ?? [];

		if ( empty( $post_ids ) ) {
			return false;
		}

		// Get batch ID.
		$state    = $this->get_state();
		$batch_id = $state['batch_id'] ?? '';

		// Batch fetch snapshots for this chunk.
		$snapshots = Batch_Helper::batch_fetch_snapshots( $batch_id, $post_ids );

		if ( empty( $snapshots ) ) {
			// All posts in chunk failed.
			$this->increment_failed( count( $post_ids ) );
			return false;
		}

		// Prepare content updates indexed by post_id.
		$posts_to_update = [];
		foreach ( $snapshots as $post_id => $snapshot ) {
			if ( ! empty( $snapshot->original_content ) ) {
				$posts_to_update[ $post_id ] = $snapshot->original_content;
				$this->increment_processed();
			} else {
				$this->log_error( 'Bulk Restore', 'rank_math_bulk_modifier_errors', $post_id, 'Empty snapshot content' );
				$this->increment_failed();
			}
		}

		// Batch update post content using Batch_Helper.
		if ( ! empty( $posts_to_update ) ) {
			Batch_Helper::update_post_content( $posts_to_update );
		}

		return false; // Remove from queue.
	}

	/**
	 * Increment processed counter.
	 *
	 * Uses in-memory counter and flushes to DB every 50 items for performance.
	 */
	private function increment_processed() {
		++$this->processed_batch;
		$this->maybe_flush_progress();
	}

	/**
	 * Increment failed counter.
	 *
	 * Uses in-memory counter and flushes to DB every 50 items for performance.
	 *
	 * @param int $count Number of times to increment. Default 1.
	 */
	private function increment_failed( $count = 1 ) {
		$this->failed_batch += $count;
		$this->maybe_flush_progress();
	}

	/**
	 * Flush progress counters to database if threshold reached.
	 *
	 * Updates DB every 50 items instead of every item to reduce DB load.
	 */
	private function maybe_flush_progress() {
		$total_count = $this->processed_batch + $this->failed_batch;

		// Flush every 50 items.
		if ( $total_count >= 50 ) {
			$this->flush_progress();
		}
	}

	/**
	 * Flush in-memory progress counters to database.
	 */
	private function flush_progress() {
		if ( $this->processed_batch === 0 && $this->failed_batch === 0 ) {
			return;
		}

		$state = $this->get_state();

		if ( $this->processed_batch > 0 ) {
			$state['processed']    = ( $state['processed'] ?? 0 ) + $this->processed_batch;
			$this->processed_batch = 0;
		}

		if ( $this->failed_batch > 0 ) {
			$state['failed']    = ( $state['failed'] ?? 0 ) + $this->failed_batch;
			$this->failed_batch = 0;
		}

		$this->set_state( $state );
	}

	/**
	 * Batch update snapshot link_changes using direct SQL with CASE WHEN.
	 *
	 * @param string $batch_id            Batch ID.
	 * @param array  $snapshots_to_update Associative array of post_id => link_changes_json.
	 */
	private function batch_update_snapshots( $batch_id, $snapshots_to_update ) {
		global $wpdb;

		if ( empty( $snapshots_to_update ) ) {
			return;
		}

		// Build CASE WHEN for link_changes.
		$case_whens = [];
		foreach ( $snapshots_to_update as $post_id => $link_changes_json ) {
			$case_whens[] = $wpdb->prepare( 'WHEN %d THEN %s', $post_id, $link_changes_json );
		}

		$case_when_sql = implode( ' ', $case_whens );
		$post_ids_sql  = implode( ',', array_map( 'intval', array_keys( $snapshots_to_update ) ) );

		// Execute batch update.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}rank_math_link_genius_snapshots
				SET link_changes = CASE post_id {$case_when_sql} END
				WHERE batch_id = %s AND post_id IN ({$post_ids_sql})",
				$batch_id
			)
		);
	}
}
