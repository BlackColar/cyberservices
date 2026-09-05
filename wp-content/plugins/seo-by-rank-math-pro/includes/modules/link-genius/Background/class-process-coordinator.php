<?php
/**
 * Process Coordinator for Link Genius background operations.
 *
 * Coordinates write-heavy processes to prevent lock contention with read-heavy
 * processes (especially the Link Status Crawler).
 *
 * @since      1.0.100
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Link_Genius\Background;

defined( 'ABSPATH' ) || exit;

/**
 * Process_Coordinator class.
 *
 * Provides coordination flags to prevent concurrent read-heavy processes
 * (like Link Status Crawler) from running during write-heavy operations
 * (like Bulk Update or Regeneration).
 *
 * Why this is critical:
 * - Crawler does full table scan with LEFT JOIN (100k+ rows)
 * - If scan hits row being deleted/inserted → blocks for 100+ seconds
 * - Causes cascading deadlocks across entire site
 *
 * Solution:
 * - Write processes call mark_write_active() before starting
 * - Crawler checks is_write_active() and pauses if true
 * - Crawler resumes after writes complete
 */
class Process_Coordinator {

	/**
	 * Transient key for tracking active write operations.
	 */
	const WRITE_ACTIVE_KEY = 'rank_math_write_operations_active';

	/**
	 * Transient key for global process lock.
	 */
	const GLOBAL_LOCK_KEY = 'rank_math_link_genius_global_lock';

	/**
	 * Lock duration in seconds (5 minutes).
	 */
	const LOCK_DURATION = 300;

	/**
	 * Mark a write process as active.
	 *
	 * @param string $process_name Process identifier (e.g., 'bulk_update', 'regenerate').
	 */
	public static function mark_write_active( $process_name ) {
		$active_writers = get_transient( self::WRITE_ACTIVE_KEY );
		if ( ! is_array( $active_writers ) ) {
			$active_writers = [];
		}

		$active_writers[ $process_name ] = time();
		set_transient( self::WRITE_ACTIVE_KEY, $active_writers, self::LOCK_DURATION );
	}

	/**
	 * Mark a write process as complete.
	 *
	 * @param string $process_name Process identifier.
	 */
	public static function mark_write_complete( $process_name ) {
		$active_writers = get_transient( self::WRITE_ACTIVE_KEY );
		if ( ! is_array( $active_writers ) ) {
			return;
		}

		unset( $active_writers[ $process_name ] );

		if ( empty( $active_writers ) ) {
			delete_transient( self::WRITE_ACTIVE_KEY );
		} else {
			set_transient( self::WRITE_ACTIVE_KEY, $active_writers, self::LOCK_DURATION );
		}
	}

	/**
	 * Check if any write operations are currently active.
	 *
	 * @return bool True if write operations are active, false otherwise.
	 */
	public static function is_write_active() {
		$active = get_transient( self::WRITE_ACTIVE_KEY );
		return ! empty( $active );
	}

	/**
	 * Acquire global process lock.
	 *
	 * Prevents multiple Link Genius processes from running concurrently.
	 * Only ONE Link Genius process (across all types) can run at any time.
	 *
	 * Why this is critical:
	 * - Bulk Update, Regeneration, and Reprocess all hit same tables
	 * - Concurrent DELETE/INSERT patterns create gap lock collisions
	 * - Lock overlap multiplies contention exponentially
	 *
	 * Solution:
	 * - Each process must acquire global lock before running
	 * - If lock is held by another process, current process pauses
	 * - Lock auto-expires after 5 minutes (handles crashes)
	 *
	 * @param string $process_name Process identifier (e.g., 'bulk_update', 'regenerate').
	 * @return bool True if lock acquired, false if another process is running.
	 */
	public static function acquire_lock( $process_name ) {
		$lock = get_transient( self::GLOBAL_LOCK_KEY );

		// Check if lock is held by a different process.
		if ( $lock && $lock !== $process_name ) {
			// Another process is running.
			return false;
		}

		// Acquire or refresh the lock.
		set_transient( self::GLOBAL_LOCK_KEY, $process_name, self::LOCK_DURATION );
		return true;
	}

	/**
	 * Release global process lock.
	 *
	 * @param string $process_name Process identifier.
	 */
	public static function release_lock( $process_name ) {
		$lock = get_transient( self::GLOBAL_LOCK_KEY );

		// Only release if this process owns the lock.
		if ( $lock === $process_name ) {
			delete_transient( self::GLOBAL_LOCK_KEY );
		}
	}
}
