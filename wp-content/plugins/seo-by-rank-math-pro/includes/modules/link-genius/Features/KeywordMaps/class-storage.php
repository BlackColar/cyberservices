<?php
/**
 * Keyword Maps Storage - Database operations for keyword maps and variations.
 *
 * Provides CRUD operations for keyword maps and their variations.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Keyword_Maps
 */

namespace RankMathPro\Link_Genius\Features\KeywordMaps;

use RankMathPro\Link_Genius\Data\Query_Builder;

defined( 'ABSPATH' ) || exit;

/**
 * Storage class.
 *
 * Handles database operations for keyword maps and variations.
 */
class Storage {

	/**
	 * Singleton instance.
	 *
	 * @var Storage
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return Storage
	 */
	public static function get() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {}

	/**
	 * Get a single keyword map by ID.
	 *
	 * @param int $id Keyword map ID.
	 * @return object|null Keyword map object or null if not found.
	 */
	public function get_keyword_map( $id ) {
		global $wpdb;

		$result = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}rank_math_link_genius_maps WHERE id = %d",
				$id
			)
		);

		return $result;
	}

	/**
	 * Get keyword maps with filtering, sorting, and pagination.
	 *
	 * @param array $args {
	 *     Optional. Query arguments.
	 *
	 *     @type bool   $enabled           Filter by enabled status.
	 *     @type bool   $auto_link_enabled Filter by auto-link enabled status.
	 *     @type string $search            Search in name and description.
	 *     @type int    $per_page          Number of items per page. 0 for no limit.
	 *     @type int    $page              Page number (1-indexed).
	 *     @type string $orderby           Column to order by.
	 *     @type string $order             Order direction (ASC/DESC).
	 * }
	 * @return array Array of keyword map objects.
	 */
	public function get_keyword_maps( $args = [] ) {
		global $wpdb;

		$defaults = [
			'per_page' => 20,
			'page'     => 1,
			'orderby'  => 'created_at',
			'order'    => 'DESC',
		];

		$args = wp_parse_args( $args, $defaults );

		// Build WHERE clause.
		$where = $this->build_keyword_maps_where( $args );

		// Build ORDER BY.
		$allowed_orderby = [ 'id', 'name', 'created_at', 'updated_at', 'last_executed_at', 'execution_count' ];
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
		$order           = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$orderby_sql     = "ORDER BY `{$orderby}` {$order}";

		// Build LIMIT.
		$limit_sql = '';
		if ( $args['per_page'] > 0 ) {
			$offset    = ( $args['page'] - 1 ) * $args['per_page'];
			$limit_sql = $wpdb->prepare( 'LIMIT %d OFFSET %d', $args['per_page'], $offset );
		}

		// Execute query.
		$results = $wpdb->get_results(
			"SELECT * FROM {$wpdb->prefix}rank_math_link_genius_maps {$where} {$orderby_sql} {$limit_sql}"
		);

		return $results ? $results : [];
	}

	/**
	 * Build WHERE clause for keyword maps query.
	 *
	 * @param array $args Query arguments.
	 * @return string WHERE clause (including WHERE keyword) or empty string.
	 */
	private function build_keyword_maps_where( $args ) {
		global $wpdb;

		$conditions = [];

		// Filter by enabled status.
		if ( isset( $args['enabled'] ) ) {
			$conditions[] = $wpdb->prepare( 'is_enabled = %d', $args['enabled'] ? 1 : 0 );
		}

		// Filter by auto-link enabled status.
		if ( isset( $args['auto_link_enabled'] ) ) {
			$conditions[] = $wpdb->prepare( 'auto_link_on_publish = %d', $args['auto_link_enabled'] ? 1 : 0 );
		}

		// Search in name and description.
		if ( ! empty( $args['search'] ) ) {
			$search       = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$conditions[] = $wpdb->prepare( '(name LIKE %s OR description LIKE %s)', $search, $search );
		}

		if ( empty( $conditions ) ) {
			return '';
		}

		return 'WHERE ' . implode( ' AND ', $conditions );
	}

	/**
	 * Save a keyword map (insert or update).
	 *
	 * @param array $data Keyword map data.
	 * @return int|false Keyword map ID on success, false on failure.
	 */
	public function save_keyword_map( $data ) {
		global $wpdb;

		$table = $wpdb->prefix . 'rank_math_link_genius_maps';

		// Prepare data for database.
		$db_data = $this->prepare_keyword_map_data( $data );

		if ( ! empty( $data['id'] ) ) {
			// Update existing.
			$id                    = absint( $data['id'] );
			$db_data['updated_at'] = current_time( 'mysql' );

			$result = $wpdb->update(
				$table,
				$db_data,
				[ 'id' => $id ],
				$this->get_format( $db_data ),
				[ '%d' ]
			);

			// Invalidate cache.
			Query_Builder::invalidate_cache();

			return false !== $result ? $id : false;
		}

		// Insert new.
		$db_data['created_at'] = current_time( 'mysql' );

		$result = $wpdb->insert(
			$table,
			$db_data,
			$this->get_format( $db_data )
		);

		// Invalidate cache.
		Query_Builder::invalidate_cache();

		return false !== $result ? $wpdb->insert_id : false;
	}

	/**
	 * Prepare keyword map data for database.
	 *
	 * @param array $data Raw data.
	 * @return array Prepared data.
	 */
	private function prepare_keyword_map_data( $data ) {
		$prepared = [];

		if ( isset( $data['name'] ) ) {
			$prepared['name'] = sanitize_text_field( $data['name'] );
		}

		if ( isset( $data['description'] ) ) {
			$prepared['description'] = sanitize_textarea_field( $data['description'] );
		}

		if ( isset( $data['target_url'] ) ) {
			$prepared['target_url'] = esc_url_raw( $data['target_url'] );
		}

		if ( isset( $data['is_enabled'] ) ) {
			$prepared['is_enabled'] = $data['is_enabled'] ? 1 : 0;
		}

		if ( isset( $data['max_links_per_post'] ) ) {
			$prepared['max_links_per_post'] = max( 1, absint( $data['max_links_per_post'] ) );
		}

		if ( isset( $data['auto_link_on_publish'] ) ) {
			$prepared['auto_link_on_publish'] = $data['auto_link_on_publish'] ? 1 : 0;
		}

		if ( isset( $data['case_sensitive'] ) ) {
			$prepared['case_sensitive'] = $data['case_sensitive'] ? 1 : 0;
		}

		return $prepared;
	}

	/**
	 * Get format array for wpdb operations.
	 *
	 * @param array $data Data array.
	 * @return array Format array.
	 */
	private function get_format( $data ) {
		$formats = [
			'name'                 => '%s',
			'description'          => '%s',
			'target_url'           => '%s',
			'is_enabled'           => '%d',
			'max_links_per_post'   => '%d',
			'auto_link_on_publish' => '%d',
			'case_sensitive'       => '%d',
			'created_at'           => '%s',
			'updated_at'           => '%s',
			'last_executed_at'     => '%s',
			'execution_count'      => '%d',
		];

		$result = [];
		foreach ( array_keys( $data ) as $key ) {
			$result[] = isset( $formats[ $key ] ) ? $formats[ $key ] : '%s';
		}

		return $result;
	}

	/**
	 * Delete a keyword map.
	 *
	 * @param int $id Keyword map ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_keyword_map( $id ) {
		global $wpdb;

		// Variations will be deleted automatically via foreign key cascade.
		$result = $wpdb->delete(
			$wpdb->prefix . 'rank_math_link_genius_maps',
			[ 'id' => $id ],
			[ '%d' ]
		);

		// Invalidate cache.
		Query_Builder::invalidate_cache();

		return false !== $result;
	}

	/**
	 * Bulk delete keyword maps.
	 *
	 * @param array $ids Array of keyword map IDs.
	 * @return int Number of deleted records.
	 */
	public function bulk_delete_keyword_maps( $ids ) {
		global $wpdb;

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids          = array_map( 'absint', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$delete_sql   = "DELETE FROM {$wpdb->prefix}rank_math_link_genius_maps WHERE id IN ($placeholders)";
		$result       = $wpdb->query( $wpdb->prepare( $delete_sql, ...$ids ) );

		// Invalidate cache.
		Query_Builder::invalidate_cache();

		return $result ? $result : 0;
	}

	/**
	 * Toggle enabled status for a keyword map.
	 *
	 * @param int  $id      Keyword map ID.
	 * @param bool $enabled New enabled status.
	 * @return bool True on success, false on failure.
	 */
	public function toggle_keyword_map( $id, $enabled ) {
		global $wpdb;

		$result = $wpdb->update(
			$wpdb->prefix . 'rank_math_link_genius_maps',
			[
				'is_enabled' => $enabled ? 1 : 0,
				'updated_at' => current_time( 'mysql' ),
			],
			[ 'id' => $id ],
			[ '%d', '%s' ],
			[ '%d' ]
		);

		// Invalidate cache.
		Query_Builder::invalidate_cache();

		return false !== $result;
	}

	/**
	 * Bulk toggle enabled status for keyword maps.
	 *
	 * @param array $ids     Array of keyword map IDs.
	 * @param bool  $enabled New enabled status.
	 * @return int Number of updated records.
	 */
	public function bulk_toggle_keyword_maps( $ids, $enabled ) {
		global $wpdb;

		if ( empty( $ids ) ) {
			return 0;
		}

		$ids          = array_map( 'absint', $ids );
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		$update_sql   = "UPDATE {$wpdb->prefix}rank_math_link_genius_maps
			SET is_enabled = %d, updated_at = %s
			WHERE id IN ($placeholders)";

		$result = $wpdb->query(
			$wpdb->prepare(
				$update_sql,
				$enabled ? 1 : 0,
				current_time( 'mysql' ),
				...$ids
			)
		);

		// Invalidate cache.
		Query_Builder::invalidate_cache();

		return $result ? $result : 0;
	}

	/**
	 * Count keyword maps.
	 *
	 * @param array $args Optional filters.
	 * @return int Count.
	 */
	public function count_keyword_maps( $args = [] ) {
		global $wpdb;

		$where = $this->build_keyword_maps_where( $args );
		$count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_link_genius_maps {$where}"
		);

		return (int) $count;
	}

	/**
	 * Update execution stats for a keyword map.
	 *
	 * @param int $id Keyword map ID.
	 * @return bool True on success, false on failure.
	 */
	public function update_execution_stats( $id ) {
		global $wpdb;

		$result = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$wpdb->prefix}rank_math_link_genius_maps
				SET last_executed_at = %s, execution_count = execution_count + 1
				WHERE id = %d",
				current_time( 'mysql' ),
				$id
			)
		);

		return false !== $result;
	}

	/**
	 * Update execution stats for multiple keyword maps in a single query.
	 *
	 * @param array $map_ids Array of keyword map IDs.
	 * @return bool True on success, false on failure.
	 */
	public function update_execution_stats_batch( $map_ids ) {
		global $wpdb;

		if ( empty( $map_ids ) ) {
			return false;
		}

		$map_ids      = array_map( 'absint', $map_ids );
		$placeholders = implode( ',', array_fill( 0, count( $map_ids ), '%d' ) );
		$timestamp    = current_time( 'mysql' );
		$update_sql   = "UPDATE {$wpdb->prefix}rank_math_link_genius_maps
			SET last_executed_at = %s, execution_count = execution_count + 1
			WHERE id IN ($placeholders)";

		$result = $wpdb->query( $wpdb->prepare( $update_sql, $timestamp, ...$map_ids ) );

		return false !== $result;
	}

	/**
	 * Get execution stats for a keyword map.
	 *
	 * @param int $id Keyword map ID.
	 * @return array Stats array.
	 */
	public function get_execution_stats( $id ) {
		global $wpdb;

		// Count executions from bulk_update_history where keyword_map_id is in filters.
		$executions = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->prefix}rank_math_link_genius_history
				WHERE filters LIKE %s",
				'%"keyword_map_id":' . $id . '%'
			)
		);

		return [
			'execution_count' => (int) $executions,
		];
	}

	// ==========================================
	// Variation Methods
	// ==========================================

	/**
	 * Get variations for a keyword map.
	 *
	 * @param int $keyword_map_id Keyword map ID.
	 * @return array Array of variation objects.
	 */
	public function get_variations( $keyword_map_id ) {
		global $wpdb;

		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$wpdb->prefix}rank_math_link_genius_map_variations
				WHERE keyword_map_id = %d
				ORDER BY created_at ASC",
				$keyword_map_id
			)
		);

		return $results ? $results : [];
	}

	/**
	 * Get variations for multiple keyword maps in a single query.
	 *
	 * @param array $keyword_map_ids Array of keyword map IDs.
	 * @return array Associative array with keyword_map_id as key and array of variation objects as value.
	 */
	public function get_variations_batch( $keyword_map_ids ) {
		global $wpdb;

		if ( empty( $keyword_map_ids ) ) {
			return [];
		}

		$keyword_map_ids = array_map( 'absint', $keyword_map_ids );
		$placeholders    = implode( ',', array_fill( 0, count( $keyword_map_ids ), '%d' ) );
		$query           = "SELECT * FROM {$wpdb->prefix}rank_math_link_genius_map_variations
			WHERE keyword_map_id IN ($placeholders)
			ORDER BY keyword_map_id, created_at ASC";

		$results = $wpdb->get_results( $wpdb->prepare( $query, ...$keyword_map_ids ) );

		// Group by keyword_map_id.
		$grouped = [];
		foreach ( $results as $variation ) {
			$grouped[ (int) $variation->keyword_map_id ][] = $variation;
		}

		return $grouped;
	}

	/**
	 * Add a variation to a keyword map.
	 *
	 * @param int    $keyword_map_id Keyword map ID.
	 * @param string $variation      Variation text.
	 * @param string $source         Source (ai or manual).
	 * @return int|false Variation ID on success, false on failure.
	 */
	public function add_variation( $keyword_map_id, $variation, $source = 'manual' ) {
		global $wpdb;

		// Validate source.
		$source = in_array( $source, [ 'ai', 'manual' ], true ) ? $source : 'manual';

		$result = $wpdb->insert(
			$wpdb->prefix . 'rank_math_link_genius_map_variations',
			[
				'keyword_map_id' => absint( $keyword_map_id ),
				'variation'      => sanitize_text_field( $variation ),
				'source'         => $source,
				'created_at'     => current_time( 'mysql' ),
			],
			[ '%d', '%s', '%s', '%s' ]
		);

		return false !== $result ? $wpdb->insert_id : false;
	}

	/**
	 * Add multiple variations to a keyword map.
	 *
	 * @param int    $keyword_map_id Keyword map ID.
	 * @param array  $variations     Array of variation texts.
	 * @param string $source         Source (ai or manual).
	 * @return int Number of variations added.
	 */
	public function add_variations( $keyword_map_id, $variations, $source = 'manual' ) {
		$added = 0;

		foreach ( $variations as $variation ) {
			$variation = trim( $variation );
			if ( empty( $variation ) ) {
				continue;
			}

			// Insert will fail silently for duplicates due to unique constraint.
			$result = $this->add_variation( $keyword_map_id, $variation, $source );
			if ( $result ) {
				++$added;
			}
		}

		return $added;
	}

	/**
	 * Delete a variation.
	 *
	 * @param int $id Variation ID.
	 * @return bool True on success, false on failure.
	 */
	public function delete_variation( $id ) {
		global $wpdb;

		$result = $wpdb->delete(
			$wpdb->prefix . 'rank_math_link_genius_map_variations',
			[ 'id' => $id ],
			[ '%d' ]
		);

		return false !== $result;
	}

	/**
	 * Delete all variations for a keyword map.
	 *
	 * @param int    $keyword_map_id Keyword map ID.
	 * @param string $source         Optional. Only delete variations from this source.
	 * @return int Number of deleted variations.
	 */
	public function delete_variations( $keyword_map_id, $source = null ) {
		global $wpdb;

		$where  = [ 'keyword_map_id' => $keyword_map_id ];
		$format = [ '%d' ];

		if ( $source ) {
			$where['source'] = $source;
			$format[]        = '%s';
		}

		$result = $wpdb->delete(
			$wpdb->prefix . 'rank_math_link_genius_map_variations',
			$where,
			$format
		);

		return $result ? $result : 0;
	}

	/**
	 * Count variations for a keyword map.
	 *
	 * @param int    $keyword_map_id Keyword map ID.
	 * @param string $source         Optional. Only count variations from this source.
	 * @return int Count.
	 */
	public function count_variations( $keyword_map_id, $source = null ) {
		global $wpdb;

		if ( $source ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_link_genius_map_variations
					WHERE keyword_map_id = %d AND source = %s",
					$keyword_map_id,
					$source
				)
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_link_genius_map_variations
					WHERE keyword_map_id = %d",
					$keyword_map_id
				)
			);
		}

		return (int) $count;
	}

	/**
	 * Check if a variation exists for a keyword map.
	 *
	 * @param int    $keyword_map_id Keyword map ID.
	 * @param string $variation      Variation text.
	 * @return bool True if exists.
	 */
	public function variation_exists( $keyword_map_id, $variation ) {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT id FROM {$wpdb->prefix}rank_math_link_genius_map_variations
				WHERE keyword_map_id = %d AND LOWER(variation) = LOWER(%s)
				LIMIT 1",
				$keyword_map_id,
				$variation
			)
		);

		return ! empty( $exists );
	}

	// ==========================================
	// Duplicate Check Methods
	// ==========================================

	/**
	 * Check if a keyword map name already exists.
	 *
	 * @param string   $name       Name to check.
	 * @param int|null $exclude_id Optional ID to exclude (for updates).
	 * @return object|null Existing keyword map if found, null otherwise.
	 */
	public function find_duplicate_name( $name, $exclude_id = null ) {
		global $wpdb;

		$name = trim( $name );
		if ( empty( $name ) ) {
			return null;
		}

		if ( $exclude_id ) {
			return $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, name FROM {$wpdb->prefix}rank_math_link_genius_maps WHERE LOWER(name) = LOWER(%s) AND id != %d LIMIT 1",
					$name,
					$exclude_id
				)
			);
		}

		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT id, name FROM {$wpdb->prefix}rank_math_link_genius_maps WHERE LOWER(name) = LOWER(%s) LIMIT 1",
				$name
			)
		);
	}

	/**
	 * Check if a keyword exists as a name or variation in any other keyword map.
	 *
	 * @param string   $keyword    Keyword to check.
	 * @param int|null $exclude_id Optional keyword map ID to exclude (for updates).
	 * @return array|null Array with 'type' ('name' or 'variation') and 'keyword_map' object if found, null otherwise.
	 */
	public function find_duplicate_keyword( $keyword, $exclude_id = null ) {
		global $wpdb;

		$keyword = trim( $keyword );
		if ( empty( $keyword ) ) {
			return null;
		}

		// First check if it exists as a keyword map name.
		if ( $exclude_id ) {
			$existing_map = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, name FROM {$wpdb->prefix}rank_math_link_genius_maps
					WHERE LOWER(name) = LOWER(%s) AND id != %d
					LIMIT 1",
					$keyword,
					$exclude_id
				)
			);
		} else {
			$existing_map = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT id, name FROM {$wpdb->prefix}rank_math_link_genius_maps
					WHERE LOWER(name) = LOWER(%s)
					LIMIT 1",
					$keyword
				)
			);
		}

		if ( $existing_map ) {
			return [
				'type'        => 'name',
				'keyword_map' => $existing_map,
			];
		}

		// Then check if it exists as a variation in any other keyword map.
		if ( $exclude_id ) {
			$existing_variation = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT v.id, v.keyword_map_id, v.variation, m.name as keyword_map_name
					FROM {$wpdb->prefix}rank_math_link_genius_map_variations v
					JOIN {$wpdb->prefix}rank_math_link_genius_maps m ON v.keyword_map_id = m.id
					WHERE LOWER(v.variation) = LOWER(%s) AND v.keyword_map_id != %d
					LIMIT 1",
					$keyword,
					$exclude_id
				)
			);
		} else {
			$existing_variation = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT v.id, v.keyword_map_id, v.variation, m.name as keyword_map_name
					FROM {$wpdb->prefix}rank_math_link_genius_map_variations v
					JOIN {$wpdb->prefix}rank_math_link_genius_maps m ON v.keyword_map_id = m.id
					WHERE LOWER(v.variation) = LOWER(%s)
					LIMIT 1",
					$keyword
				)
			);
		}

		if ( $existing_variation ) {
			return [
				'type'        => 'variation',
				'keyword_map' => (object) [
					'id'   => $existing_variation->keyword_map_id,
					'name' => $existing_variation->keyword_map_name,
				],
			];
		}

		return null;
	}

	/**
	 * Check if any of the provided variations already exist in other keyword maps.
	 *
	 * @param array    $variations Array of variation texts to check.
	 * @param int|null $exclude_id Optional keyword map ID to exclude (for updates).
	 * @return array Array of duplicates found, each with 'variation', 'type', and 'keyword_map'.
	 */
	public function find_duplicate_variations( $variations, $exclude_id = null ) {
		$duplicates = [];

		foreach ( $variations as $variation ) {
			$text = is_array( $variation ) && isset( $variation['variation'] ) ? $variation['variation'] : $variation;
			if ( is_array( $text ) ) {
				continue;
			}
			$text = trim( $text );

			if ( empty( $text ) ) {
				continue;
			}

			$duplicate = $this->find_duplicate_keyword( $text, $exclude_id );
			if ( $duplicate ) {
				$duplicates[] = [
					'variation'   => $text,
					'type'        => $duplicate['type'],
					'keyword_map' => $duplicate['keyword_map'],
				];
			}
		}

		return $duplicates;
	}
}
