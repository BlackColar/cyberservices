<?php
/**
 * Background process for Link Status checking.
 *
 * @since      1.0.99
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Link_Genius\Background;

use RankMath\Helper;
use RankMath\Helpers\DB as DB_Helper;
use RankMathPro\Link_Genius\Data\Query_Builder;
use RankMathPro\Link_Genius\Services\Robots_Checker;

defined( 'ABSPATH' ) || exit;

/**
 * Link_Status_Crawler class.
 *
 * Handles background HTTP status checking for all links with:
 * - Rate limiting (100ms delay between requests)
 * - Robots.txt checking
 * - URL deduplication via url_hash
 * - Retry logic with exponential backoff
 */
class Link_Status_Crawler extends \WP_Background_Process {

	use Progress_Helper;
	use Coordinated_Processor;

	/**
	 * Action identifier.
	 *
	 * @var string
	 */
	protected $action = 'link_status_crawler';

	/**
	 * Save the current queue to a unique batch key.
	 *
	 * Overrides parent to use uniqid() instead of microtime(), preventing key
	 * collisions when save() is called multiple times in rapid succession.
	 *
	 * @return $this
	 */
	public function save() {
		if ( empty( $this->data ) ) {
			return $this;
		}

		$key = $this->identifier . '_batch_' . md5( uniqid( '', true ) . wp_rand() );
		update_site_option( $key, $this->data );
		$this->data = [];

		return $this;
	}

	/**
	 * Main instance.
	 *
	 * Ensure only one instance is loaded or can be loaded.
	 *
	 * @return Link_Status_Crawler
	 */
	public static function get() {
		static $instance;

		if ( is_null( $instance ) && ! ( $instance instanceof Link_Status_Crawler ) ) {
			$instance = new Link_Status_Crawler();
		}

		return $instance;
	}

	/**
	 * Start status checking with filters.
	 *
	 * @param array $args Arguments with 'links' array or filter criteria.
	 */
	public function start( $args = [] ) {
		// Cancel any existing crawler to clear old queue.
		if ( $this->is_active() ) {
			$this->cancel();
		}

		// Clear any previous completion data.
		delete_option( 'rank_math_link_genius_audit_crawler_completed' );

		$links = isset( $args['links'] ) ? $args['links'] : [];

		if ( ! empty( $links ) ) {
			// User provided explicit list of links, queue them all at once.
			$unique_urls_count     = count( $links );
			$total_instances_count = $this->get_total_link_instances( $args );

			// Initialize progress tracking.
			$this->init_progress_state(
				$unique_urls_count,
				[
					'total_instances' => $total_instances_count,
				]
			);

			// Save in chunks of 100 — each chunk becomes its own batch row,
			// preventing large wp_options writes that cause binlog growth.
			foreach ( array_chunk( $links, 100 ) as $chunk ) {
				foreach ( $chunk as $link ) {
					$this->push_to_queue( $link );
				}
				$this->save();
			}

			$this->dispatch();
			return;
		}

		// No explicit links provided, use cursor-based chunked fetching.
		// Get total counts first (expensive, but only once).
		$unique_urls_count     = $this->get_total_unique_urls( $args );
		$total_instances_count = $this->get_total_link_instances( $args );

		if ( $unique_urls_count === 0 ) {
			return; // No links to check.
		}

		// Initialize progress tracking.
		$this->init_progress_state(
			$unique_urls_count,
			[
				'total_instances' => $total_instances_count,
			]
		);

		// Queue links in chunks of 100 — each chunk becomes its own batch row,
		// preventing large wp_options writes that cause binlog growth.
		$cursor     = '';
		$chunk_size = 100;

		while ( true ) {
			$links = $this->get_links_to_check( $args, $cursor, $chunk_size );

			if ( empty( $links ) ) {
				break; // No more links.
			}

			// Persist this chunk as its own batch row.
			foreach ( $links as $link ) {
				$this->push_to_queue( $link );
			}
			$this->save();

			// Update cursor to last url_hash for next chunk.
			$last_link = end( $links );
			$cursor    = $last_link['url_hash'];

			// If we got fewer links than requested, we're done.
			if ( count( $links ) < $chunk_size ) {
				break;
			}
		}

		$this->dispatch();
	}

	/**
	 * Queue multiple links for status checking.
	 *
	 * Public API method for queueing links for background HTTP status checking.
	 * Handles both single and multiple links efficiently.
	 *
	 * Features:
	 * - 24-hour deduplication (skips recently checked links).
	 * - Single database query for batch deduplication check.
	 * - Async dispatch (non-blocking via WP cron).
	 * - Automatic progress tracking (shows in Overview tab).
	 *
	 * Use cases:
	 * - Post save operations (links extracted from content).
	 * - Bulk recheck operations.
	 * - Manual recheck requests.
	 * - Background processing jobs.
	 *
	 * @param array $links_data Array of link data arrays with keys: url, url_hash, type, link_id (optional).
	 */
	public static function queue_links_batch( $links_data ) {
		if ( empty( $links_data ) ) {
			return;
		}

		global $wpdb;
		$instance     = self::get();
		$status_table = $wpdb->prefix . 'rank_math_link_genius_audit';
		$links_table  = $wpdb->prefix . 'rank_math_internal_links';

		// Deduplicate links by url_hash - only need to check each unique URL once.
		// save_status() will create records for all link_ids with the same url_hash.
		$unique_links = [];
		foreach ( $links_data as $link_data ) {
			$url_hash = $link_data['url_hash'] ?? '';
			if ( $url_hash && ! isset( $unique_links[ $url_hash ] ) ) {
				$unique_links[ $url_hash ] = $link_data;
			}
		}

		if ( empty( $unique_links ) ) {
			return;
		}

		// Get url_hashes that were checked recently (within 1 day).
		$url_hashes   = array_keys( $unique_links );
		$placeholders = implode( ',', array_fill( 0, count( $url_hashes ), '%s' ) );

		$recently_checked = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT url_hash FROM `{$status_table}`
				WHERE url_hash IN ({$placeholders})
				AND last_checked_at > %s",
				array_merge( $url_hashes, [ gmdate( 'Y-m-d H:i:s', strtotime( '-1 day' ) ) ] )
			)
		);

		$recently_checked_set = array_flip( $recently_checked );

		// For recently checked URLs, ensure all link_ids have status records.
		// Copy status from existing records (by url_hash) to any link_ids missing status.
		if ( ! empty( $recently_checked ) ) {
			$rc_placeholders = implode( ',', array_fill( 0, count( $recently_checked ), '%s' ) );
			$insert_query    = "INSERT IGNORE INTO `{$status_table}` (link_id, url_hash, http_status_code, status_category, robots_blocked, last_checked_at, last_error_message, is_marked_safe)
				SELECT l.id, l.url_hash, s.http_status_code, s.status_category, s.robots_blocked, s.last_checked_at, s.last_error_message, s.is_marked_safe
				FROM `{$links_table}` l
				INNER JOIN `{$status_table}` s ON s.url_hash = l.url_hash
				LEFT JOIN `{$status_table}` existing ON existing.link_id = l.id
				WHERE l.url_hash IN ({$rc_placeholders})
				AND existing.id IS NULL
				GROUP BY l.id";

			$wpdb->query( $wpdb->prepare( $insert_query, $recently_checked ) );
		}

		// Queue unique links that weren't checked recently.
		// Collect links to queue, then save in chunks of 100.
		$to_queue = [];
		foreach ( $unique_links as $url_hash => $link_data ) {
			if ( isset( $recently_checked_set[ $url_hash ] ) ) {
				// URL was recently checked and status records have been copied above.
				continue;
			}

			$to_queue[] = [
				'link_id'  => $link_data['link_id'] ?? 0,
				'url'      => $link_data['url'],
				'url_hash' => $link_data['url_hash'],
				'type'     => $link_data['type'],
			];
		}

		if ( empty( $to_queue ) ) {
			return;
		}

		// Save in chunks of 100 — each chunk becomes its own batch row,
		// preventing large wp_options writes that cause binlog growth.
		foreach ( array_chunk( $to_queue, 100 ) as $chunk ) {
			foreach ( $chunk as $item ) {
				$instance->push_to_queue( $item );
			}
			$instance->save();
		}
		$instance->update_progress_tracking();

		// Schedule async dispatch via WP cron (non-blocking).
		if ( ! wp_next_scheduled( 'rank_math_link_genius_dispatch_crawler' ) ) {
			wp_schedule_single_event( time() + 5, 'rank_math_link_genius_dispatch_crawler' );
		}
	}

	/**
	 * Process all pending link queues stored in options.
	 *
	 * This is the WP Cron event handler for 'rank_math_link_genius_queue_pending_links'.
	 * It processes all pending queue options created during post saves and then deletes them.
	 *
	 * @return void
	 */
	public static function process_pending_link_queues() {
		global $wpdb;

		// Find all pending queue options (limit to 100 posts per execution).
		$pending = $wpdb->get_col(
			"SELECT option_name FROM {$wpdb->options}
			 WHERE option_name LIKE 'rm_lg_pending_queue_%'
			 LIMIT 100"
		);

		if ( empty( $pending ) ) {
			return;
		}

		foreach ( $pending as $option_name ) {
			$link_data = get_option( $option_name );

			if ( ! empty( $link_data ) && is_array( $link_data ) ) {
				self::queue_links_batch( $link_data );
			}

			delete_option( $option_name );
		}
	}

	/**
	 * Check a single link immediately (synchronously).
	 *
	 * @param array $link Link data with id, url, url_hash, type.
	 * @return bool True on success, false on failure.
	 */
	public static function check_link_immediately( $link ) {
		$instance = self::get();

		$url_to_check = $link['url'];

		// Validate URL format.
		if ( ! filter_var( $url_to_check, FILTER_VALIDATE_URL ) ) {
			// Invalid URL format.
			$instance->save_status(
				$link,
				$instance->build_status_array(
					[
						'status_category'    => 'error',
						'last_error_message' => 'Invalid URL format',
					]
				)
			);
			return true;
		}

		// Check robots.txt.
		if ( $instance->check_and_handle_robots( $link, $url_to_check ) ) {
			return true;
		}

		// Check URL status.
		$result = $instance->check_url_status( $url_to_check );
		// Clear is_marked_safe when rechecking.
		$result['is_marked_safe'] = 0;
		$instance->save_status( $link, $result );

		return true;
	}

	/**
	 * Handle cron healthcheck.
	 *
	 * Pause crawler if write operations are active.
	 * Crawler does full table scan (100k+ rows) with NOT IN subquery.
	 * If scan hits row being deleted/inserted → blocks for 100+ seconds.
	 * This causes site-wide performance degradation.
	 *
	 * Solution: Check if bulk update/regeneration is running and pause if so.
	 *
	 * @return void
	 */
	public function handle() {
		// Check if write operations are active.
		if ( Process_Coordinator::is_write_active() ) {
			$this->schedule_event( time() + 100 );
			return;
		}

		parent::handle();
	}

	/**
	 * Handle cron healthcheck.
	 *
	 * Overrides WP_Background_Process::handle_cron_healthcheck() to call handle()
	 * directly instead of making a loopback wp_remote_post() to admin-ajax.php.
	 *
	 * Since wp-cron.php already runs in a separate PHP process, calling handle()
	 * directly is functionally equivalent — we skip the unnecessary HTTP hop.
	 */
	public function handle_cron_healthcheck() {
		// Check the process lock transient directly instead of using is_processing() /
		// is_process_running(), because older bundled copies of WP_Background_Process.
		if ( get_site_transient( $this->identifier . '_process_lock' ) ) {
			// Another worker is already processing the queue.
			return;
		}

		if ( $this->is_queue_empty() ) {
			// Nothing left to do — remove the recurring healthcheck.
			$this->clear_scheduled_event();
			return;
		}

		// Reschedule the next healthcheck (normally done inside dispatch()).
		$this->schedule_event();

		// Process directly in this cron process — no HTTP loopback needed.
		$this->handle();
	}

	/**
	 * Get crawler progress.
	 *
	 * @return array|null Progress data or null if not crawling.
	 */
	public static function get_progress() {
		$instance = self::get();
		$state    = $instance->get_state();

		if ( empty( $state ) ) {
			return null;
		}

		// Format progress using helper trait.
		$progress = self::format_progress( $state );

		// Add crawler-specific fields.
		if ( isset( $state['total_instances'] ) ) {
			$progress['total_instances'] = $state['total_instances'];
		}

		// Calculate successful (processed - failed).
		$processed  = $state['processed'] ?? 0;
		$failed     = $state['failed'] ?? 0;
		$successful = $processed - $failed;

		$progress['successful'] = $successful;

		return $progress;
	}

	/**
	 * Clear completion data.
	 *
	 * Called when user dismisses the completion notice.
	 */
	public static function clear_completion() {
		Helper::remove_notification( 'rank_math_link_genius_audit_crawler_complete' );

		$instance = self::get();
		$instance->clear_state();
	}

	/**
	 * Check if crawler is active.
	 *
	 * @return bool True if crawler is active.
	 */
	public function is_active() {
		return self::is_operation_active( $this->get_state() );
	}

	/**
	 * Get total count of items in the queue.
	 *
	 * @return int Number of items in queue.
	 */
	public function get_queue_count() {
		global $wpdb;

		// WP_Background_Process stores queue items in wp_options with key pattern.
		// wp_rank_math_link_genius_audit_crawler_batch_{batch_id}.
		$table  = $wpdb->options;
		$column = 'option_name';

		if ( is_multisite() ) {
			$table  = $wpdb->sitemeta;
			$column = 'meta_key';
		}

		$key = $wpdb->esc_like( $this->identifier . '_batch_' ) . '%';

		// Count all batch items.
		$count   = 0;
		$batches = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT {$column} FROM {$table} WHERE {$column} LIKE %s",
				$key
			)
		);

		foreach ( $batches as $batch_key ) {
			$batch  = get_site_option( $batch_key, [] );
			$count += is_array( $batch ) ? count( $batch ) : 0;
		}

		return $count;
	}

	/**
	 * Update progress tracking options for queued items.
	 *
	 * Initializes or updates the total count so progress shows in the Overview tab.
	 * Called when links are queued on post save or manual audit start.
	 */
	public function update_progress_tracking() {
		// Only initialize progress if not already running a manual audit.
		$state         = $this->get_state();
		$current_total = $state['total'] ?? 0;
		$queue_count   = $this->get_queue_count();

		if ( 0 === $current_total ) {
			// No audit running, initialize progress tracking.
			if ( $queue_count > 0 ) {
				$this->init_progress_state( $queue_count );
			}
		} else {
			// Manual audit is running, add new items to the total.
			$processed = $state['processed'] ?? 0;
			$new_items = $queue_count - ( $current_total - $processed );
			if ( $new_items > 0 ) {
				$state['total'] = $current_total + $new_items;
				$this->set_state( $state );
			}
		}
	}

	/**
	 * Cancel crawler.
	 *
	 * Uses optimized cancellation to avoid memory exhaustion with large queues.
	 * Extends trait cancellation with memory-efficient batch deletion.
	 */
	public function cancel() {
		// Stop the background process by clearing the cron event.
		$this->clear_scheduled_event();

		// Delete all queued batches (memory efficient).
		$this->delete_batches_from_db();

		// Unlock the parent process lock (from WP_Background_Process).
		$this->unlock_process();

		// Clean up coordination state (from Coordinated_Processor trait).
		$operation = $this->get_operation_name();
		Process_Coordinator::mark_write_complete( $operation );
		Process_Coordinator::release_lock( $operation );

		// Clear progress state (from Progress_Helper trait).
		$this->clear_state();
	}

	/**
	 * Clean up orphaned status records where the link no longer exists.
	 *
	 * Called weekly via WP cron to prevent status table bloat.
	 * Deletes status records that have no matching url_hash in the links table
	 * and were last checked more than 30 days ago.
	 */
	public static function cleanup_orphaned_status() {
		global $wpdb;
		$status_table = $wpdb->prefix . 'rank_math_link_genius_audit';
		$links_table  = $wpdb->prefix . 'rank_math_internal_links';

		// Delete status records that:
		// 1. Have no matching url_hash in internal_links (truly orphaned).
		// 2. Were last checked more than 30 days ago.
		$wpdb->query(
			"DELETE ls FROM `{$status_table}` ls
			 LEFT JOIN `{$links_table}` l ON ls.url_hash = l.url_hash
			 WHERE l.id IS NULL
			 AND ls.last_checked_at < DATE_SUB(NOW(), INTERVAL 30 DAY)"
		);
	}

	/**
	 * Task to perform - check single URL.
	 *
	 * @param array $link Link data with url, url_hash, id, type.
	 * @return bool False to remove from queue, true to retry.
	 */
	protected function task( $link ) {
		try {
			// Validate link data.
			if ( ! isset( $link['url'] ) || ! isset( $link['url_hash'] ) ) {
				// Count as processed even if invalid - we still examined it.
				$this->increment_progress( 1 );
				return false; // Invalid data, skip.
			}

			$url_to_check = $link['url'];

			// Check robots.txt and handle if blocked.
			if ( ! $this->check_and_handle_robots( $link, $url_to_check ) ) {
				// Not blocked by robots, check URL status.
				$result = $this->check_url_status( $url_to_check );
				$this->save_status( $link, $result );
			}

			// Update progress counter using trait method.
			$this->increment_progress( 1 );

			// Successfully processed, remove from queue.
			return false;
		} catch ( \Exception $error ) {
			// Error occurred, update failed count.
			$failed = (int) get_option( 'rank_math_link_genius_audit_crawler_failed', 0 );
			update_option( 'rank_math_link_genius_audit_crawler_failed', $failed + 1, false );

			// Retry this link.
			return true;
		}
	}

	/**
	 * Get state option name for this processor.
	 *
	 * @return string
	 */
	protected function get_state_option_name() {
		return 'rank_math_link_genius_audit_crawler_state';
	}

	/**
	 * Get operation name for coordination.
	 *
	 * @return string
	 */
	protected function get_operation_name() {
		return 'crawler';
	}

	/**
	 * Complete.
	 *
	 * Called when all items have been processed.
	 */
	protected function complete() {
		// Get counts from state.
		$state           = $this->get_state();
		$total           = $state['total'] ?? 0;
		$total_instances = $state['total_instances'] ?? 0;
		$processed       = $state['processed'] ?? 0;
		$failed          = $state['failed'] ?? 0;
		$successful      = $processed - $failed;

		// Mark operation as completed in state.
		$this->mark_completed();

		// Release process coordination locks.
		// This must happen here because WP_Background_Process::handle() calls
		// wp_die() after complete(), which terminates PHP and prevents the
		// finally block in Coordinated_Processor::handle() from executing.
		$operation = $this->get_operation_name();
		Process_Coordinator::mark_write_complete( $operation );
		Process_Coordinator::release_lock( $operation );

		// Call parent::complete() to clear scheduled events and trigger completed hook.
		parent::complete();
	}

	/**
	 * Build WHERE conditions for link filtering.
	 *
	 * @param array  $args        Filter arguments (is_internal, unchecked_only, broken_only).
	 * @param string $table_alias Table alias for the links table (e.g., 'l' or 'l2').
	 * @return array WHERE clause conditions.
	 */
	private function build_filter_conditions( $args, $table_alias = 'l' ) {
		global $wpdb;

		$conditions = [ "{$table_alias}.url_hash IS NOT NULL" ];

		// Filter by type (internal/external).
		if ( ! empty( $args['is_internal'] ) ) {
			$conditions[] = $wpdb->prepare(
				"{$table_alias}.type = %s",
				'internal' === $args['is_internal'] ? 'internal' : 'external'
			);
		}

		// Unchecked only.
		if ( ! empty( $args['unchecked_only'] ) ) {
			$conditions[] = 'ls.id IS NULL';
		}

		// Broken only.
		if ( ! empty( $args['broken_only'] ) ) {
			$conditions[] = "ls.status_category IN ('4xx', '5xx', 'timeout', 'error')";
		}

		return $conditions;
	}

	/**
	 * Get links to check based on filters with cursor-based pagination.
	 *
	 * @param array  $args   Filter arguments.
	 * @param string $cursor Last url_hash from previous chunk (empty on first chunk).
	 * @param int    $limit  Number of links to fetch.
	 * @return array Links to check.
	 */
	private function get_links_to_check( $args, $cursor = '', $limit = 10000 ) {
		global $wpdb;

		$links_table  = $wpdb->prefix . 'rank_math_internal_links';
		$status_table = $wpdb->prefix . 'rank_math_link_genius_audit';
		$posts_table  = $wpdb->posts;

		// WHERE conditions for the SUBQUERY (uses l2, ls).
		$sub_where     = $this->build_filter_conditions( $args, 'l2' );
		$sub_where_sql = implode( ' AND ', $sub_where );

		// Add cursor condition for pagination.
		$cursor_condition = '';
		if ( ! empty( $cursor ) ) {
			$cursor_condition = $wpdb->prepare( ' AND l2.url_hash > %s', $cursor );
		}

		$sql = "
			SELECT
				l.url_hash,
				l.id AS link_id,
				l.url,
				l.type
			FROM {$links_table} l
			JOIN (
				SELECT
					MIN(l2.id) AS min_id,
					l2.url_hash
				FROM {$links_table} l2
				JOIN {$posts_table} p
					ON p.ID = l2.post_id
				AND p.post_status IN ('publish', 'future')
				LEFT JOIN {$status_table} ls
					ON l2.id = ls.link_id
				WHERE {$sub_where_sql}{$cursor_condition}
				GROUP BY l2.url_hash
				ORDER BY l2.url_hash ASC
				LIMIT {$limit}
			) t ON t.min_id = l.id
			ORDER BY l.url_hash
		";

		return $wpdb->get_results( $sql, ARRAY_A );
	}

	/**
	 * Get total count of link instances matching filters (before deduplication).
	 *
	 * @param array $args Filter arguments.
	 * @return int Total link instances.
	 */
	private function get_total_link_instances( $args ) {
		global $wpdb;

		$links_table  = $wpdb->prefix . 'rank_math_internal_links';
		$status_table = $wpdb->prefix . 'rank_math_link_genius_audit';
		$posts_table  = $wpdb->posts;

		// Build same WHERE conditions as get_links_to_check().
		$sub_where = $this->build_filter_conditions( $args, 'l' );
		$where_sql = implode( ' AND ', $sub_where );

		// Count ALL matching links (no GROUP BY).
		$sql = "
			SELECT COUNT(*)
			FROM {$links_table} l
			JOIN {$posts_table} p
				ON p.ID = l.post_id
				AND p.post_status IN ('publish', 'future')
			LEFT JOIN {$status_table} ls
				ON l.id = ls.link_id
			WHERE {$where_sql}
		";

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Get total count of unique URLs matching filters (after deduplication).
	 *
	 * This is the actual number of HTTP checks that will be performed.
	 *
	 * @param array $args Filter arguments.
	 * @return int Total unique URLs.
	 */
	private function get_total_unique_urls( $args ) {
		global $wpdb;

		$links_table  = $wpdb->prefix . 'rank_math_internal_links';
		$status_table = $wpdb->prefix . 'rank_math_link_genius_audit';
		$posts_table  = $wpdb->posts;

		// Build same WHERE conditions as get_links_to_check().
		$sub_where = $this->build_filter_conditions( $args, 'l' );
		$where_sql = implode( ' AND ', $sub_where );

		// Count unique url_hash values (this is what gets crawled).
		$sql = "
			SELECT COUNT(DISTINCT l.url_hash)
			FROM {$links_table} l
			JOIN {$posts_table} p
				ON p.ID = l.post_id
				AND p.post_status IN ('publish', 'future')
			LEFT JOIN {$status_table} ls
				ON l.id = ls.link_id
			WHERE {$where_sql}
		";

		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Check robots.txt and save blocked status if necessary.
	 *
	 * @param array  $link Link data.
	 * @param string $url  URL to check.
	 * @return bool True if robots blocked (status saved), false if allowed.
	 */
	private function check_and_handle_robots( $link, $url ) {
		$robots_checker = Robots_Checker::get();
		$robots_allowed = $robots_checker->is_allowed( $url );

		if ( ! $robots_allowed ) {
			$this->save_status(
				$link,
				$this->build_status_array(
					[
						'robots_blocked'  => 1,
						'status_category' => 'blocked',
					]
				)
			);
			return true;
		}

		return false;
	}

	/**
	 * Get standard HTTP request options.
	 *
	 * @param bool $sslverify Whether to verify SSL certificates.
	 * @return array Request options for wp_remote_head/wp_remote_get.
	 */
	private function get_request_options( $sslverify = true ) {
		/**
		 * Filter: 'rank_math/link_genius/crawler_request_timeout' - HTTP timeout per URL check.
		 *
		 * @param int $timeout Timeout in seconds.
		 */
		$timeout = (int) apply_filters( 'rank_math/link_genius/crawler_request_timeout', 5 );

		return [
			'timeout'     => $timeout,
			'redirection' => 0,
			'user-agent'  => 'RankMath Link Checker/1.0 (+' . home_url() . ')',
			'sslverify'   => $sslverify,
		];
	}

	/**
	 * Check URL HTTP status.
	 *
	 * @param string $url URL to check.
	 * @return array Status data.
	 */
	private function check_url_status( $url ) {
		// Try HEAD request first (faster).
		$response = wp_remote_head( $url, $this->get_request_options() );

		// If HEAD fails with SSL error, retry without SSL verification.
		if ( is_wp_error( $response ) && $this->is_ssl_error( $response ) ) {
			$response = wp_remote_head( $url, $this->get_request_options( false ) );
		}

		// If HEAD still fails, try GET.
		if ( is_wp_error( $response ) ) {
			$response = wp_remote_get( $url, $this->get_request_options() );

			// If GET fails with SSL error, retry without SSL verification.
			if ( is_wp_error( $response ) && $this->is_ssl_error( $response ) ) {
				$response = wp_remote_get( $url, $this->get_request_options( false ) );
			}
		}

		// Handle errors.
		if ( is_wp_error( $response ) ) {
			return $this->handle_error( $response );
		}

		// Get status code.
		$code = wp_remote_retrieve_response_code( $response );

		// Treat soft redirects (trailing slash, www, http→https) as 200 OK.
		if ( $code >= 300 && $code < 400 ) {
			$location = wp_remote_retrieve_header( $response, 'location' );

			if ( ! empty( $location ) && $this->is_url_normalization( $url, $location ) ) {
				$code = 200;
			}
		}

		return $this->build_status_array(
			[
				'http_status_code' => $code,
				'status_category'  => $this->get_status_category( $code ),
			]
		);
	}

	/**
	 * Build status data array with defaults.
	 *
	 * @param array $overrides Status fields to override defaults.
	 * @return array Complete status data array.
	 */
	private function build_status_array( $overrides = [] ) {
		$defaults = [
			'http_status_code'   => null,
			'status_category'    => 'error',
			'robots_blocked'     => 0,
			'last_checked_at'    => current_time( 'mysql' ),
			'last_error_message' => null,
			'is_marked_safe'     => 0,
		];

		return array_merge( $defaults, $overrides );
	}

	/**
	 * Handle request error.
	 *
	 * @param \WP_Error $error WordPress error object.
	 * @return array Status data.
	 */
	private function handle_error( $error ) {
		$error_code    = $error->get_error_code();
		$error_message = $error->get_error_message();

		// Categorize error.
		$category = 'error';
		if ( 'http_request_failed' === $error_code && strpos( $error_message, 'timed out' ) !== false ) {
			$category = 'timeout';
		}

		return $this->build_status_array(
			[
				'status_category'    => $category,
				'last_error_message' => $error_message,
			]
		);
	}

	/**
	 * Check if a WP_Error is an SSL-specific error.
	 *
	 * @param \WP_Error $error WordPress error object.
	 * @return bool True if this is an SSL error.
	 */
	private function is_ssl_error( $error ) {
		$message = strtolower( $error->get_error_message() );

		$ssl_indicators = [
			'ssl',
			'certificate',
			'curl error 35',
			'curl error 51',
			'curl error 60',
		];

		foreach ( $ssl_indicators as $indicator ) {
			if ( strpos( $message, $indicator ) !== false ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Get status category from HTTP code.
	 *
	 * @param int $code HTTP status code.
	 * @return string Category (2xx, 3xx, 4xx, 5xx, error).
	 */
	private function get_status_category( $code ) {
		if ( $code >= 200 && $code < 300 ) {
			return '2xx';
		}
		if ( $code >= 300 && $code < 400 ) {
			return '3xx';
		}
		if ( $code >= 400 && $code < 500 ) {
			return '4xx';
		}
		if ( $code >= 500 && $code < 600 ) {
			return '5xx';
		}
		return 'error';
	}

	/**
	 * Check if a redirect is just a URL normalization (not a real redirect to flag).
	 *
	 * Common normalizations that should be ignored:
	 * - Adding/removing www subdomain (google.com -> www.google.com)
	 * - Adding/removing trailing slash (example.com/path -> example.com/path/)
	 * - HTTP to HTTPS upgrade (http://example.com -> https://example.com)
	 * - Combination of the above
	 *
	 * @param string $original_url The original URL that was checked.
	 * @param string $redirect_url The URL it redirects to.
	 * @return bool True if this is just a normalization, false if it's a real redirect.
	 */
	private function is_url_normalization( $original_url, $redirect_url ) {
		// Parse both URLs.
		$original = wp_parse_url( $original_url );
		$redirect = wp_parse_url( $redirect_url );

		if ( ! $original || ! $redirect ) {
			return false;
		}

		// Normalize the paths for comparison (handle trailing slashes).
		$original_path = isset( $original['path'] ) ? rtrim( $original['path'], '/' ) : '';
		$redirect_path = isset( $redirect['path'] ) ? rtrim( $redirect['path'], '/' ) : '';

		// Check if paths are the same (ignoring trailing slash).
		if ( $original_path !== $redirect_path ) {
			return false;
		}

		// Check if query strings are the same.
		$original_query = isset( $original['query'] ) ? $original['query'] : '';
		$redirect_query = isset( $redirect['query'] ) ? $redirect['query'] : '';
		if ( $original_query !== $redirect_query ) {
			return false;
		}

		// Check if fragments are the same.
		$original_fragment = isset( $original['fragment'] ) ? $original['fragment'] : '';
		$redirect_fragment = isset( $redirect['fragment'] ) ? $redirect['fragment'] : '';
		if ( $original_fragment !== $redirect_fragment ) {
			return false;
		}

		// Normalize hostnames (remove/add www).
		$original_host = isset( $original['host'] ) ? strtolower( $original['host'] ) : '';
		$redirect_host = isset( $redirect['host'] ) ? strtolower( $redirect['host'] ) : '';

		$original_host_no_www = preg_replace( '/^www\./', '', $original_host );
		$redirect_host_no_www = preg_replace( '/^www\./', '', $redirect_host );

		// Check if hosts are the same (ignoring www).
		if ( $original_host_no_www !== $redirect_host_no_www ) {
			return false;
		}

		return true;
	}

	/**
	 * Save status to database.
	 *
	 * @param array $link   Link data.
	 * @param array $status Status data.
	 */
	private function save_status( $link, $status ) {
		global $wpdb;
		$table = $wpdb->prefix . 'rank_math_link_genius_audit';

		// Get all link IDs with same url_hash for deduplication.
		$link_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM `{$wpdb->prefix}rank_math_internal_links` WHERE url_hash = %s",
				$link['url_hash']
			)
		);

		if ( empty( $link_ids ) ) {
			return;
		}

		// Build values for bulk insert.
		$values       = [];
		$current_time = $status['last_checked_at'] ?? current_time( 'mysql' );

		foreach ( $link_ids as $link_id ) {
			$values[] = $wpdb->prepare(
				'(%d, %s, %s, %s, %d, %s, %s, %d)',
				$link_id,
				$link['url_hash'],
				$status['http_status_code'] ?? null,
				$status['status_category'] ?? null,
				$status['robots_blocked'] ?? 0,
				$current_time,
				$status['last_error_message'] ?? null,
				$status['is_marked_safe'] ?? 0
			);
		}

		// Single INSERT...ON DUPLICATE KEY UPDATE (1 query for all link_ids).
		$wpdb->query(
			"INSERT INTO `{$table}`
			(link_id, url_hash, http_status_code, status_category, robots_blocked, last_checked_at, last_error_message, is_marked_safe)
			VALUES " . implode( ', ', $values ) . '
			ON DUPLICATE KEY UPDATE
				http_status_code = VALUES(http_status_code),
				status_category = VALUES(status_category),
				robots_blocked = VALUES(robots_blocked),
				last_checked_at = VALUES(last_checked_at),
				last_error_message = VALUES(last_error_message),
				is_marked_safe = VALUES(is_marked_safe)'
		);

		// Invalidate query cache.
		Query_Builder::invalidate_cache();
	}
}
