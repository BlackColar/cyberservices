<?php
/**
 * Keyword Map Processor for Link Genius.
 *
 * Handles background processing for keyword map execution.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Keyword_Maps
 */

namespace RankMathPro\Link_Genius\Features\KeywordMaps;

use WP_Error;
use RankMath\Helper;
use RankMathPro\Link_Genius\Background\Process_Coordinator;
use RankMathPro\Link_Genius\Background\Processor_Utils;
use RankMathPro\Link_Genius\Services\Batch_Helper;

defined( 'ABSPATH' ) || exit;

/**
 * Keyword_Map_Processor class.
 *
 * Extends WP_Background_Process to handle keyword map execution in chunks.
 */
class Keyword_Map_Processor extends \WP_Background_Process {

	use \RankMathPro\Link_Genius\Background\Progress_Helper;
	use \RankMathPro\Link_Genius\Background\Coordinated_Processor;

	/**
	 * Action.
	 *
	 * @var string
	 */
	protected $action = 'link_genius_keyword_map';

	/**
	 * Get state option name for this processor.
	 *
	 * @return string
	 */
	protected function get_state_option_name() {
		return 'rank_math_keyword_map_current';
	}

	/**
	 * Get the operation name for lock coordination.
	 *
	 * @return string
	 */
	protected function get_operation_name() {
		return 'keyword_map';
	}

	/**
	 * Main instance.
	 *
	 * Ensure only one instance is loaded or can be loaded.
	 *
	 * @return Keyword_Map_Processor
	 */
	public static function get() {
		static $instance;

		if ( is_null( $instance ) && ! ( $instance instanceof Keyword_Map_Processor ) ) {
			$instance = new Keyword_Map_Processor();
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
			'rank_math/link_genius/keyword_map_chunk_size',
			10
		);
	}

	/**
	 * Start keyword map execution process.
	 *
	 * @param object $keyword_map       Keyword map object.
	 * @param array  $selected_items    Optional array of link_ids to apply.
	 * @param array  $preview_results   Preview results containing changes to apply.
	 * @param bool   $is_rollback       Whether this is a rollback operation.
	 * @param string $rollback_batch_id Batch ID being rolled back (if rollback).
	 * @return array|WP_Error Process start details or error.
	 */
	public function start( $keyword_map, $selected_items = null, $preview_results = null, $is_rollback = false, $rollback_batch_id = null ) {
		global $wpdb;

		// Check if another keyword map execution is already running using shared validation.
		$existing_progress = $this->get_state();
		$validation_result = Processor_Utils::validate_not_in_progress( $existing_progress, 'keyword map execution' );
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

			// If selected_items provided, filter snapshots using shared utility.
			if ( ! empty( $selected_items ) && is_array( $selected_items ) ) {
				$snapshots = Processor_Utils::filter_snapshots_by_selection( $snapshots, $selected_items, 'link_id' );
			}

			// Calculate totals using shared utility.
			$totals      = Processor_Utils::calculate_snapshot_totals( $snapshots );
			$post_ids    = $totals['post_ids'];
			$total_posts = $totals['total_posts'];
			$total_links = $totals['total_links'];
		} else {
			// NORMAL EXECUTION MODE: Validate preview results.
			if ( empty( $preview_results ) || ! is_array( $preview_results ) ) {
				return new WP_Error(
					'no_preview_data',
					__( 'Preview data is required to execute keyword map.', 'rank-math-pro' ),
					[ 'status' => 400 ]
				);
			}

			$all_changes = isset( $preview_results['sample_changes'] ) ? $preview_results['sample_changes'] : [];

			// If selected_items provided, filter to only those items.
			if ( ! empty( $selected_items ) && is_array( $selected_items ) ) {
				$all_changes = array_filter(
					$all_changes,
					function ( $change ) use ( $selected_items ) {
						return in_array( $change['link_id'], $selected_items, true );
					}
				);
			}

			if ( empty( $all_changes ) ) {
				return new WP_Error(
					'no_changes_found',
					__( 'No changes found to apply.', 'rank-math-pro' )
				);
			}

			// Group changes by post_id.
			$changes_by_post = [];
			foreach ( $all_changes as $change ) {
				$post_id = $change['post_id'];
				if ( ! isset( $changes_by_post[ $post_id ] ) ) {
					$changes_by_post[ $post_id ] = [];
				}
				$changes_by_post[ $post_id ][] = $change;
			}

			$post_ids    = array_keys( $changes_by_post );
			$total_posts = count( $post_ids );
			$total_links = count( $all_changes );
		}

		// Generate unique batch ID for history tracking (only for normal execution).
		if ( ! $is_rollback ) {
			$batch_id = md5( 'keyword_map_' . $keyword_map->id . '_' . time() );

			// Create history record.
			$wpdb->insert(
				$wpdb->prefix . 'rank_math_link_genius_history',
				[
					'batch_id'             => $batch_id,
					'source_type'          => 'keyword_map',
					'keyword_map_id'       => $keyword_map->id,
					'user_id'              => get_current_user_id(),
					'operation_type'       => 'add_link',
					'filters'              => wp_json_encode( [ 'keyword_map_id' => $keyword_map->id ] ),
					'changes_summary'      => wp_json_encode(
						[
							'target_url' => $keyword_map->target_url,
							'keyword'    => $keyword_map->name,
						]
					),
					'affected_links_count' => $total_links,
					'affected_posts_count' => $total_posts,
					'status'               => 'processing',
				],
				[
					'%s', // batch_id.
					'%s', // source_type.
					'%d', // keyword_map_id.
					'%d', // user_id.
					'%s', // operation_type.
					'%s', // filters.
					'%s', // changes_summary.
					'%d', // affected_links_count.
					'%d', // affected_posts_count.
					'%s', // status.
				]
			);
		} else {
			// For rollback, use the rollback_batch_id.
			$batch_id = $rollback_batch_id;
		}

		// Store state for processing.
		$state = [
			'keyword_map_id'    => $keyword_map->id,
			'batch_id'          => $batch_id,
			'status'            => 'processing',
			'total_posts'       => $total_posts,
			'total_links'       => $total_links,
			'processed_posts'   => 0,
			'processed_links'   => 0,
			'percent'           => 0,
			'started_at'        => current_time( 'mysql' ),
			'active'            => true,
			'is_rollback'       => $is_rollback,
			'rollback_batch_id' => $rollback_batch_id,
		];

		// Add keyword map specific config only for normal execution.
		if ( ! $is_rollback ) {
			$state['target_url']         = $keyword_map->target_url;
			$state['max_links_per_post'] = $keyword_map->max_links_per_post;
		}

		$this->set_state( $state );

		// Store changes in queue items instead of wp_options.
		// This distributes data across queue records instead of one massive option.
		foreach ( $post_ids as $post_id ) {
			$queue_item = [
				'post_id'  => $post_id,
				'batch_id' => $batch_id,
			];

			// For normal execution, include post_changes.
			if ( ! $is_rollback ) {
				$queue_item['post_changes'] = $changes_by_post[ $post_id ];
			}

			$this->push_to_queue( $queue_item );
		}

		// Dispatch the batch.
		$this->save()->dispatch();

		$message = $is_rollback
			? __( 'Rollback started. Processing in background.', 'rank-math-pro' )
			: __( 'Keyword map execution started. Processing in background.', 'rank-math-pro' );

		return [
			'success'       => true,
			'batch_id'      => $batch_id,
			'is_background' => true,
			'total_links'   => $total_links,
			'total_posts'   => $total_posts,
			'message'       => $message,
		];
	}

	/**
	 * Passthrough — Coordinated_Processor is used only for cancel(), not lock coordination on handle().
	 */
	protected function handle() { // @phpcs:ignore Generic.CodeAnalysis.UselessOverridingMethod.Found
		parent::handle();
	}

	/**
	 * Task handler for processing a single post.
	 *
	 * @param array $item Task item containing post_id and batch_id.
	 * @return bool False to remove from queue.
	 */
	protected function task( $item ) {
		global $wpdb;

		$post_id  = $item['post_id'];
		$batch_id = $item['batch_id'];

		// Get current progress.
		$progress = $this->get_state();
		if ( empty( $progress ) ) {
			return false;
		}

		// Check if this is a rollback operation.
		$is_rollback       = ! empty( $progress['is_rollback'] );
		$rollback_batch_id = ! empty( $progress['rollback_batch_id'] ) ? $progress['rollback_batch_id'] : null;

		if ( $is_rollback && $rollback_batch_id ) {
			// ROLLBACK MODE: Restore post from snapshot.
			$snapshot = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT original_content, link_changes FROM {$wpdb->prefix}rank_math_link_genius_snapshots WHERE batch_id = %s AND post_id = %d",
					$rollback_batch_id,
					$post_id
				)
			);

			if ( ! $snapshot ) {
				return false; // No snapshot found.
			}

			// Allow page-builder integrations (e.g. Elementor) to handle content
			// restore directly (e.g. writing back to _elementor_data instead of
			// post_content). Returns true when the restore was handled.
			$restore_handled = apply_filters(
				'rank_math/link_genius/keyword_map_restore_post',
				false,
				$post_id,
				$snapshot->original_content
			);

			if ( ! $restore_handled ) {
				// Restore original content via normal path.
				Batch_Helper::update_post_content( [ $post_id => $snapshot->original_content ] );
			}

			// Count the number of links being rolled back.
			$link_changes      = json_decode( $snapshot->link_changes, true );
			$links_rolled_back = ! empty( $link_changes ) && is_array( $link_changes ) ? count( $link_changes ) : 0;

			// Update progress.
			++$progress['processed_posts'];
			$progress['processed_links'] += $links_rolled_back;
			$progress['percent']          = self::calculate_percent( $progress['processed_posts'], $progress['total_posts'] );

			$this->set_state( $progress );

			return false; // Remove from queue.
		}

		// NORMAL EXECUTION MODE.
		$target_url   = $progress['target_url'];
		$max_per_post = (int) $progress['max_links_per_post'] ?? 3;

		// Get changes from queue item instead of option.
		$post_changes = $item['post_changes'] ?? [];

		// Skip if no changes for this post.
		if ( empty( $post_changes ) ) {
			return false;
		}

		// Allow page-builder integrations (e.g. Elementor) to fully handle execution
		// for this post — writing links directly into their own data store (e.g.
		// _elementor_data) and creating the rollback snapshot. Returns false when the
		// integration does not handle the post, or an array with 'links_added' when it does.
		$page_builder_result = apply_filters(
			'rank_math/link_genius/keyword_map_process_post',
			false,
			$post_id,
			$post_changes,
			$batch_id,
			$target_url,
			$max_per_post
		);

		if ( false !== $page_builder_result ) {
			$links_added = is_array( $page_builder_result ) ? ( $page_builder_result['links_added'] ?? 0 ) : 0;

			++$progress['processed_posts'];
			$progress['processed_links'] += $links_added;
			$progress['percent']          = self::calculate_percent( $progress['processed_posts'], $progress['total_posts'] );
			$this->set_state( $progress );

			return false; // Remove from queue.
		}

		// Get post content using Batch_Helper.
		$posts = Batch_Helper::batch_fetch_posts( [ $post_id ], [ 'ID', 'post_content' ] );
		if ( empty( $posts[ $post_id ] ) ) {
			return false;
		}

		$original_content = $posts[ $post_id ]->post_content;
		$new_content      = $original_content;
		$links_added      = 0;
		$change_details   = [];

		// Sort changes by position in reverse order (highest position first).
		// This prevents position shifting when we modify the content.
		usort(
			$post_changes,
			function ( $a, $b ) {
				$pos_a = $a['position'] ?? 0;
				$pos_b = $b['position'] ?? 0;
				return $pos_b - $pos_a;
			}
		);

		// Limit to max_links_per_post.
		$changes_to_apply = array_slice( $post_changes, 0, $max_per_post );

		// Track positions we've already used to prevent duplicate linking.
		$used_positions = [];

		// Apply changes directly from preview data using exact positions.
		// Changes are already selected by the user, so we apply them exactly as previewed.
		foreach ( $changes_to_apply as $change ) {
			$anchor_text         = $change['before']['anchor_text'] ?? '';
			$target_url_for_link = $change['after']['url'] ?? $target_url;
			$position            = $change['position'] ?? null;

			// Skip if missing required data.
			if ( empty( $anchor_text ) ) {
				continue;
			}

			// Position is required for correct replacement. Without it, we can't guarantee
			// we won't create nested links. Preview must be regenerated to include positions.
			if ( null === $position ) {
				continue;
			}

			// Skip if we've already used this position.
			if ( isset( $used_positions[ $position ] ) ) {
				continue;
			}

			// Verify the anchor text at this position matches what we expect.
			$text_at_position = substr( $new_content, $position, strlen( $anchor_text ) );
			if ( $text_at_position !== $anchor_text ) {
				// Position is stale (content changed), skip this change.
				continue;
			}

			// Use exact position from preview to avoid nested links.
			$link        = sprintf( '<a href="%s">%s</a>', esc_url( $target_url_for_link ), esc_html( $anchor_text ) );
			$new_content = substr_replace( $new_content, $link, $position, strlen( $anchor_text ) );
			++$links_added;

			// Mark this position as used.
			$used_positions[ $position ] = true;

			// Store in same format as bulk update for compatibility with get_batch_changes.
			$change_details[] = [
				'link_id'    => $change['link_id'] ?? 0,
				'old_url'    => null, // No old URL for add_link operation.
				'new_url'    => $target_url_for_link,
				'old_anchor' => null, // Plain text before linking.
				'new_anchor' => $anchor_text,
			];
		}

		// Update post if content changed.
		if ( $new_content !== $original_content ) {
			// Use Batch_Helper for direct SQL update (no hooks, no revisions).
			Batch_Helper::update_post_content( [ $post_id => $new_content ] );

			// Create snapshot for rollback.
			$wpdb->insert(
				$wpdb->prefix . 'rank_math_link_genius_snapshots',
				[
					'batch_id'         => $batch_id,
					'post_id'          => $post_id,
					'original_content' => $original_content,
					'link_changes'     => wp_json_encode( $change_details ),
				],
				[
					'%s', // batch_id.
					'%d', // post_id.
					'%s', // original_content.
					'%s', // link_changes.
				]
			);
		}

		// Update progress.
		++$progress['processed_posts'];
		$progress['processed_links'] += $links_added;
		$progress['percent']          = self::calculate_percent( $progress['processed_posts'], $progress['total_posts'] );

		$this->set_state( $progress );

		return false; // Remove from queue.
	}

	/**
	 * Complete processing.
	 */
	protected function complete() {
		parent::complete();

		global $wpdb;

		// Get current progress.
		$progress = $this->get_state();
		if ( empty( $progress ) ) {
			return;
		}

		$batch_id          = $progress['batch_id'];
		$is_rollback       = ! empty( $progress['is_rollback'] );
		$rollback_batch_id = ! empty( $progress['rollback_batch_id'] ) ? $progress['rollback_batch_id'] : null;

		// Use shared completion logic.
		if ( $is_rollback && $rollback_batch_id ) {
			Processor_Utils::complete_operation( $rollback_batch_id, 'keyword_map', true );
		} else {
			Processor_Utils::complete_operation( $batch_id, 'keyword_map', false );
		}

		// Update progress to completed.
		$progress['status'] = $is_rollback ? 'rolled_back' : 'completed';
		$progress['active'] = false;
		$this->set_state( $progress );

		// Clean up preview data (only for normal execution).
		if ( ! $is_rollback && isset( $progress['keyword_map_id'] ) ) {
			$preview_processor = new Preview_Processor();
			$preview_processor->cancel( $progress['keyword_map_id'] );
		}
	}

	/**
	 * Get current progress.
	 *
	 * @return array|false Progress data or false if no progress.
	 */
	public static function get_progress() {
		$instance = self::get();
		$progress = $instance->get_state();

		if ( empty( $progress ) ) {
			return false;
		}

		$total     = $progress['total_posts'] ?? 0;
		$processed = $progress['processed_posts'] ?? 0;

		$result = [
			'keyword_map_id'  => $progress['keyword_map_id'] ?? null,
			'batch_id'        => $progress['batch_id'] ?? null,
			'status'          => $progress['status'] ?? null,
			'total'           => $total,
			'processed'       => $processed,
			'total_links'     => $progress['total_links'] ?? 0,
			'processed_links' => $progress['processed_links'] ?? 0,
			'percent'         => self::calculate_percent( $processed, $total ),
			'started_at'      => $progress['started_at'] ?? null,
			'active'          => $progress['active'] ?? false,
		];

		// Add completed_at timestamp for completed or rolled back tasks.
		// This is needed for the BackgroundProgress component to recognize completion.
		if ( ( 'completed' === $result['status'] || 'rolled_back' === $result['status'] ) && false === $result['active'] ) {
			$result['completed_at'] = current_time( 'mysql' );
		}

		return $result;
	}

	/**
	 * Clear completion data.
	 *
	 * Called when user clicks the Close button on completed progress.
	 */
	public static function clear_completion() {
		$instance = self::get();
		$instance->clear_state();
	}

	/**
	 * Cancel current execution.
	 */
	public function cancel() {
		global $wpdb;

		$progress = self::get_progress();
		if ( ! $progress ) {
			return;
		}

		// Update history record to failed.
		if ( isset( $progress['batch_id'] ) ) {
			$wpdb->update(
				$wpdb->prefix . 'rank_math_link_genius_history',
				[
					'status'        => 'failed',
					'completed_at'  => current_time( 'mysql' ),
					'error_message' => __( 'Cancelled by user.', 'rank-math-pro' ),
				],
				[ 'batch_id' => $progress['batch_id'] ],
				[ '%s', '%s', '%s' ],
				[ '%s' ]
			);
		}

		// Clear the cron event.
		$this->clear_scheduled_event();

		// Delete all queued batches (avoids the deprecated cancel_process() alias, which relies on an async loopback request).
		$this->delete_batches_from_db();

		// Unlock the parent process lock.
		$this->unlock_process();

		// Release coordination locks.
		$operation = $this->get_operation_name();
		Process_Coordinator::mark_write_complete( $operation );
		Process_Coordinator::release_lock( $operation );

		// Clear progress state.
		$this->clear_state();
	}
}
