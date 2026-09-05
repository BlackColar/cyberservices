<?php
/**
 * Keyword Maps REST API endpoints.
 *
 * Provides REST API endpoints for keyword maps management.
 *
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Keyword_Maps
 */

namespace RankMathPro\Link_Genius\Features\KeywordMaps;

use WP_Error;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use RankMath\Helper;
use RankMath\Rest\Rest_Helper;
use RankMathPro\Link_Genius\Api\Base_Operation_Controller;
use RankMathPro\Link_Genius\Services\History_Service;
use RankMathPro\Link_Genius\Features\KeywordMaps\Utils\Variation_Expander;

defined( 'ABSPATH' ) || exit;

/**
 * Rest class for Keyword Maps operations.
 */
class Rest extends Base_Operation_Controller {

	/**
	 * Storage instance.
	 *
	 * @var Storage
	 */
	protected $storage;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = Rest_Helper::BASE . '/link-genius';
		$this->storage   = Storage::get();
		$this->register_routes();
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		// Register preview and history endpoints using base class methods.
		$this->register_preview_endpoints(
			'/keyword-maps',
			[
				'preview_route'    => '/(?P<id>\d+)/preview',
				'preview_callback' => 'preview_keyword_map',
				'results_callback' => 'get_preview_results',
				'results_args'     => [
					'keyword_map_id' => [
						'description' => esc_html__( 'Keyword map ID.', 'rank-math-pro' ),
						'type'        => 'integer',
						'required'    => true,
					],
				],
			]
		);

		$this->register_history_endpoints(
			'/keyword-maps',
			[
				'get_callback'    => 'get_history',
				'register_delete' => false,
			]
		);

		// GET/POST /keyword-maps - List and create keyword maps.
		register_rest_route(
			$this->namespace,
			'/keyword-maps',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_keyword_maps' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [
						'page'     => [
							'description' => esc_html__( 'Page number.', 'rank-math-pro' ),
							'type'        => 'integer',
							'default'     => 1,
							'minimum'     => 1,
						],
						'per_page' => [
							'description' => esc_html__( 'Number of items per page.', 'rank-math-pro' ),
							'type'        => 'integer',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 100,
						],
						'orderby'  => [
							'description' => esc_html__( 'Column to order by.', 'rank-math-pro' ),
							'type'        => 'string',
							'default'     => 'created_at',
							'enum'        => [ 'id', 'name', 'created_at', 'updated_at', 'last_executed_at', 'execution_count' ],
						],
						'order'    => [
							'description' => esc_html__( 'Order direction.', 'rank-math-pro' ),
							'type'        => 'string',
							'default'     => 'DESC',
							'enum'        => [ 'ASC', 'DESC' ],
						],
						'enabled'  => [
							'description' => esc_html__( 'Filter by enabled status.', 'rank-math-pro' ),
							'type'        => 'boolean',
						],
						'search'   => [
							'description' => esc_html__( 'Search term.', 'rank-math-pro' ),
							'type'        => 'string',
						],
					],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'create_keyword_map' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => $this->get_keyword_map_args(),
				],
			]
		);

		// GET/PUT/DELETE /keyword-maps/{id} - Single keyword map operations.
		register_rest_route(
			$this->namespace,
			'/keyword-maps/(?P<id>\d+)',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_keyword_map' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
				[
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => [ $this, 'update_keyword_map' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => $this->get_keyword_map_args( false ),
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ $this, 'delete_keyword_map' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
			]
		);

		// POST /keyword-maps/bulk-toggle - Bulk enable/disable.
		register_rest_route(
			$this->namespace,
			'/keyword-maps/bulk-toggle',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk_toggle' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'ids'     => [
						'description' => esc_html__( 'Array of keyword map IDs.', 'rank-math-pro' ),
						'type'        => 'array',
						'required'    => true,
						'items'       => [ 'type' => 'integer' ],
					],
					'enabled' => [
						'description' => esc_html__( 'New enabled status.', 'rank-math-pro' ),
						'type'        => 'boolean',
						'required'    => true,
					],
				],
			]
		);

		// POST /keyword-maps/bulk-delete - Bulk delete.
		register_rest_route(
			$this->namespace,
			'/keyword-maps/bulk-delete',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'bulk_delete' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'ids' => [
						'description' => esc_html__( 'Array of keyword map IDs.', 'rank-math-pro' ),
						'type'        => 'array',
						'required'    => true,
						'items'       => [ 'type' => 'integer' ],
					],
				],
			]
		);

		// POST /keyword-maps/{id}/execute - Execute keyword map.
		register_rest_route(
			$this->namespace,
			'/keyword-maps/(?P<id>\d+)/execute',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'execute_keyword_map' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		// NOTE: Preview and history endpoints registered via base class methods above.

		// Variation endpoints.
		register_rest_route(
			$this->namespace,
			'/keyword-maps/(?P<id>\d+)/variations',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ $this, 'get_variations' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
				],
				[
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => [ $this, 'add_variation' ],
					'permission_callback' => [ $this, 'check_admin_permission' ],
					'args'                => [
						'variation' => [
							'description' => esc_html__( 'Variation text.', 'rank-math-pro' ),
							'type'        => 'string',
							'required'    => true,
						],
						'source'    => [
							'description' => esc_html__( 'Variation source.', 'rank-math-pro' ),
							'type'        => 'string',
							'default'     => 'manual',
							'enum'        => [ 'manual', 'ai' ],
						],
					],
				],
			]
		);

		// DELETE /keyword-maps/variations/{id} - Delete a variation.
		register_rest_route(
			$this->namespace,
			'/keyword-maps/variations/(?P<id>\d+)',
			[
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => [ $this, 'delete_variation' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		// POST /keyword-maps/{id}/generate-variations - Generate AI variations for existing map.
		register_rest_route(
			$this->namespace,
			'/keyword-maps/(?P<id>\d+)/generate-variations',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'generate_variations' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
			]
		);

		// POST /keyword-maps/generate-variations - Generate AI variations by keyword (for unsaved maps).
		register_rest_route(
			$this->namespace,
			'/keyword-maps/generate-variations',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'generate_variations_by_keyword' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'keyword' => [
						'description' => esc_html__( 'Keyword to generate variations for.', 'rank-math-pro' ),
						'type'        => 'string',
						'required'    => true,
					],
					'context' => [
						'description' => esc_html__( 'Optional context to help generate better variations.', 'rank-math-pro' ),
						'type'        => 'string',
					],
				],
			]
		);

		// POST /keyword-maps/{id}/accept-variations - Accept selected AI variations.
		register_rest_route(
			$this->namespace,
			'/keyword-maps/(?P<id>\d+)/accept-variations',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ $this, 'accept_variations' ],
				'permission_callback' => [ $this, 'check_admin_permission' ],
				'args'                => [
					'variations' => [
						'description' => esc_html__( 'Array of variations to accept.', 'rank-math-pro' ),
						'type'        => 'array',
						'required'    => true,
					],
				],
			]
		);
	}

	/**
	 * Get keyword map REST endpoint arguments.
	 *
	 * @param bool $required Whether name and target_url are required.
	 * @return array Arguments array.
	 */
	private function get_keyword_map_args( $required = true ) {
		return [
			'name'                 => [
				'description' => esc_html__( 'Keyword map name.', 'rank-math-pro' ),
				'type'        => 'string',
				'required'    => $required,
			],
			'description'          => [
				'description' => esc_html__( 'Keyword map description.', 'rank-math-pro' ),
				'type'        => 'string',
			],
			'target_url'           => [
				'description' => esc_html__( 'Target URL.', 'rank-math-pro' ),
				'type'        => 'string',
				'required'    => $required,
			],
			'is_enabled'           => [
				'description' => esc_html__( 'Whether the keyword map is enabled.', 'rank-math-pro' ),
				'type'        => 'boolean',
				'default'     => true,
			],
			'max_links_per_post'   => [
				'description' => esc_html__( 'Maximum links per post.', 'rank-math-pro' ),
				'type'        => 'integer',
				'default'     => 3,
				'minimum'     => 1,
			],
			'auto_link_on_publish' => [
				'description' => esc_html__( 'Enable auto-linking on publish.', 'rank-math-pro' ),
				'type'        => 'boolean',
				'default'     => false,
			],
			'case_sensitive'       => [
				'description' => esc_html__( 'Enable case-sensitive keyword matching.', 'rank-math-pro' ),
				'type'        => 'boolean',
				'default'     => false,
			],
		];
	}

	/**
	 * Get keyword maps list.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_keyword_maps( $request ) {
		$args = [
			'page'     => $request->get_param( 'page' ),
			'per_page' => $request->get_param( 'per_page' ),
			'orderby'  => $request->get_param( 'orderby' ),
			'order'    => $request->get_param( 'order' ),
		];

		if ( $request->has_param( 'enabled' ) ) {
			$args['enabled'] = $request->get_param( 'enabled' );
		}

		if ( $request->has_param( 'search' ) ) {
			$args['search'] = $request->get_param( 'search' );
		}

		$maps  = $this->storage->get_keyword_maps( $args );
		$total = $this->storage->count_keyword_maps( $args );

		// Format maps for response.
		$formatted_maps = array_map( [ $this, 'format_keyword_map' ], $maps );

		return new WP_REST_Response(
			[
				'success' => true,
				'items'   => $formatted_maps,
				'total'   => $total,
				'pages'   => ceil( $total / $args['per_page'] ),
				'page'    => $args['page'],
			],
			200
		);
	}

	/**
	 * Get a single keyword map.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function get_keyword_map( $request ) {
		$id  = (int) $request->get_param( 'id' );
		$map = $this->storage->get_keyword_map( $id );

		if ( ! $map ) {
			return new WP_Error(
				'not_found',
				__( 'Keyword map not found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		return new WP_REST_Response(
			[
				'success' => true,
				'item'    => $this->format_keyword_map( $map ),
			],
			200
		);
	}

	/**
	 * Create a keyword map.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function create_keyword_map( $request ) {
		$name = $request->get_param( 'name' );

		// Validate name for duplicates.
		$validation_error = $this->validate_keyword_map_name( $name );
		if ( is_wp_error( $validation_error ) ) {
			return $validation_error;
		}

		// Filter out duplicate variations.
		$variations          = $request->get_param( 'variations' );
		$variation_result    = $this->filter_duplicate_variations( $variations );
		$filtered_variations = $variation_result['filtered'];
		$skipped_variations  = $variation_result['skipped'];

		$data = [
			'name'                 => $name,
			'description'          => $request->get_param( 'description' ),
			'target_url'           => $request->get_param( 'target_url' ),
			'is_enabled'           => $request->get_param( 'is_enabled' ),
			'max_links_per_post'   => $request->get_param( 'max_links_per_post' ),
			'auto_link_on_publish' => $request->get_param( 'auto_link_on_publish' ),
			'case_sensitive'       => $request->get_param( 'case_sensitive' ),
		];

		$id = $this->storage->save_keyword_map( $data );

		if ( ! $id ) {
			return new WP_Error(
				'create_failed',
				__( 'Failed to create keyword map.', 'rank-math-pro' ),
				[ 'status' => 500 ]
			);
		}

		// Add only the filtered (non-duplicate) variations.
		$this->add_variations_to_map( $id, $filtered_variations );

		$map = $this->storage->get_keyword_map( $id );

		// Build response message.
		$message = $this->build_success_message(
			__( 'Keyword map created successfully.', 'rank-math-pro' ),
			$skipped_variations
		);

		$this->track_link_genius_event(
			'Feature Used',
			[
				'feature'              => 'Keyword Map Created',
				'auto_link_on_publish' => $data['auto_link_on_publish'] ? 1 : 0,
				'case_sensitive'       => $data['case_sensitive'] ? 1 : 0,
				'max_links_per_post'   => $data['max_links_per_post'],
				'variations_count'     => count( $filtered_variations ),
				'skipped_count'        => count( $skipped_variations ),
			]
		);

		return new WP_REST_Response(
			[
				'success'            => true,
				'id'                 => $id,
				'item'               => $this->format_keyword_map( $map ),
				'message'            => $message,
				'skipped_variations' => $skipped_variations,
			],
			201
		);
	}

	/**
	 * Update a keyword map.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function update_keyword_map( $request ) {
		$id  = (int) $request->get_param( 'id' );
		$map = $this->storage->get_keyword_map( $id );

		if ( ! $map ) {
			return new WP_Error(
				'not_found',
				__( 'Keyword map not found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		// Validate name for duplicates if name is being updated.
		if ( $request->has_param( 'name' ) ) {
			$validation_error = $this->validate_keyword_map_name( $request->get_param( 'name' ), $id );
			if ( is_wp_error( $validation_error ) ) {
				return $validation_error;
			}
		}

		$data = [ 'id' => $id ];

		// Only update provided fields.
		$fields = [ 'name', 'description', 'target_url', 'is_enabled', 'max_links_per_post', 'auto_link_on_publish', 'case_sensitive' ];
		foreach ( $fields as $field ) {
			if ( $request->has_param( $field ) ) {
				$data[ $field ] = $request->get_param( $field );
			}
		}

		$result = $this->storage->save_keyword_map( $data );

		if ( ! $result ) {
			return new WP_Error(
				'update_failed',
				__( 'Failed to update keyword map.', 'rank-math-pro' ),
				[ 'status' => 500 ]
			);
		}

		$updated_map = $this->storage->get_keyword_map( $id );

		$this->track_link_genius_event(
			'Feature Used',
			[
				'feature'        => 'Keyword Map Updated',
				'fields_updated' => count( $data ) - 1,
			]
		);

		return new WP_REST_Response(
			[
				'success' => true,
				'item'    => $this->format_keyword_map( $updated_map ),
				'message' => __( 'Keyword map updated successfully.', 'rank-math-pro' ),
			],
			200
		);
	}

	/**
	 * Validate keyword map name for duplicates.
	 *
	 * @param string   $name       Name to validate.
	 * @param int|null $exclude_id Optional ID to exclude (for updates).
	 * @return true|WP_Error True if valid, WP_Error if duplicate found.
	 */
	private function validate_keyword_map_name( $name, $exclude_id = null ) {
		// Check for duplicate name.
		$duplicate_name = $this->storage->find_duplicate_name( $name, $exclude_id );
		if ( $duplicate_name ) {
			return new WP_Error(
				'duplicate_name',
				__( 'A keyword map with this name already exists. Please use a different name.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		// Check if the name exists as a variation in another keyword map.
		$duplicate_keyword = $this->storage->find_duplicate_keyword( $name, $exclude_id );
		if ( $duplicate_keyword ) {
			return new WP_Error(
				'duplicate_keyword',
				sprintf(
					/* translators: %s: existing keyword map name */
					__( 'This keyword already exists in the keyword map "%s". Consider adding your variations there instead.', 'rank-math-pro' ),
					$duplicate_keyword['keyword_map']->name
				),
				[
					'status'          => 400,
					'existing_map_id' => $duplicate_keyword['keyword_map']->id,
				]
			);
		}

		return true;
	}

	/**
	 * Filter out duplicate variations and track skipped ones.
	 *
	 * @param array|null $variations  Array of variations to filter.
	 * @param int|null   $exclude_id  Optional keyword map ID to exclude.
	 * @return array Array with 'filtered' and 'skipped' keys.
	 */
	private function filter_duplicate_variations( $variations, $exclude_id = null ) {
		$result = [
			'filtered' => [],
			'skipped'  => [],
		];

		if ( empty( $variations ) || ! is_array( $variations ) ) {
			return $result;
		}

		$duplicate_variations = $this->storage->find_duplicate_variations( $variations, $exclude_id );
		$duplicate_texts      = array_map(
			function ( $d ) {
				return strtolower( trim( $d['variation'] ) );
			},
			$duplicate_variations
		);

		$seen_in_batch = [];

		foreach ( $variations as $variation ) {
			$text = sanitize_text_field( $variation['variation'] ?? '' );
			if ( empty( $text ) ) {
				continue;
			}

			$text_lower = strtolower( trim( $text ) );

			// Skip variations already seen in this batch (case-insensitive deduplication).
			if ( isset( $seen_in_batch[ $text_lower ] ) ) {
				continue;
			}

			if ( in_array( $text_lower, $duplicate_texts, true ) ) {
				// Find the duplicate info for this variation.
				foreach ( $duplicate_variations as $dup ) {
					if ( strtolower( trim( $dup['variation'] ) ) === $text_lower ) {
						$result['skipped'][] = [
							'variation'         => $text,
							'existing_map_name' => $dup['keyword_map']->name,
						];
						break;
					}
				}
			} else {
				$seen_in_batch[ $text_lower ] = true;
				$result['filtered'][]         = $variation;
			}
		}

		return $result;
	}

	/**
	 * Add variations to a keyword map.
	 *
	 * @param int   $keyword_map_id Keyword map ID.
	 * @param array $variations     Array of variations to add.
	 */
	private function add_variations_to_map( $keyword_map_id, $variations ) {
		if ( empty( $variations ) ) {
			return;
		}

		foreach ( $variations as $variation ) {
			$text   = sanitize_text_field( $variation['variation'] ?? '' );
			$source = sanitize_text_field( $variation['source'] ?? 'manual' );
			if ( ! empty( $text ) ) {
				$this->storage->add_variation( $keyword_map_id, $text, $source );
			}
		}
	}

	/**
	 * Build success message with optional skipped variations info.
	 *
	 * @param string $base_message      Base success message.
	 * @param array  $skipped_variations Array of skipped variations.
	 * @return string Final message.
	 */
	private function build_success_message( $base_message, $skipped_variations ) {
		if ( empty( $skipped_variations ) ) {
			return $base_message;
		}

		$skipped_names = array_map(
			function ( $s ) {
				return $s['variation'];
			},
			$skipped_variations
		);

		return sprintf(
			/* translators: 1: base success message, 2: comma-separated list of skipped variations */
			__( '%1$s The following variations were skipped because they already exist in other keyword maps: %2$s', 'rank-math-pro' ),
			$base_message,
			implode( ', ', $skipped_names )
		);
	}

	/**
	 * Delete a keyword map.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function delete_keyword_map( $request ) {
		$id  = (int) $request->get_param( 'id' );
		$map = $this->storage->get_keyword_map( $id );

		if ( ! $map ) {
			return new WP_Error(
				'not_found',
				__( 'Keyword map not found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		$result = $this->storage->delete_keyword_map( $id );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete keyword map.', 'rank-math-pro' ),
				[ 'status' => 500 ]
			);
		}

		$this->track_link_genius_event(
			'Button Clicked',
			[
				'button'          => 'Delete Keyword Map',
				'execution_count' => (int) $map->execution_count,
			]
		);

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Keyword map deleted successfully.', 'rank-math-pro' ),
			],
			200
		);
	}

	/**
	 * Bulk toggle keyword maps.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function bulk_toggle( $request ) {
		$ids     = $request->get_param( 'ids' );
		$enabled = $request->get_param( 'enabled' );

		$updated = $this->storage->bulk_toggle_keyword_maps( $ids, $enabled );

		$this->track_link_genius_event(
			'Button Clicked',
			[
				'button'  => 'Bulk Toggle Keyword Maps',
				'enabled' => $enabled ? 1 : 0,
				'count'   => count( $ids ),
			]
		);

		return new WP_REST_Response(
			[
				'success' => true,
				'updated' => $updated,
				'message' => sprintf(
					/* translators: %d: number of updated items */
					__( '%d keyword maps updated.', 'rank-math-pro' ),
					$updated
				),
			],
			200
		);
	}

	/**
	 * Bulk delete keyword maps.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function bulk_delete( $request ) {
		$ids = $request->get_param( 'ids' );

		$deleted = $this->storage->bulk_delete_keyword_maps( $ids );

		$this->track_link_genius_event(
			'Button Clicked',
			[
				'button' => 'Bulk Delete Keyword Maps',
				'count'  => count( $ids ),
			]
		);

		return new WP_REST_Response(
			[
				'success' => true,
				'deleted' => $deleted,
				'message' => sprintf(
					/* translators: %d: number of deleted items */
					__( '%d keyword maps deleted.', 'rank-math-pro' ),
					$deleted
				),
			],
			200
		);
	}

	/**
	 * Execute a keyword map.
	 *
	 * Finds posts containing the keyword/variations as plain text and adds links.
	 * Uses background processing for consistent behavior and better UX.
	 * Also handles rollback when is_rollback=true is passed.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function execute_keyword_map( $request ) {
		$id                = (int) $request->get_param( 'id' );
		$selected_items    = $request->get_param( 'selected_items' ); // Array of link_ids to apply.
		$is_rollback       = $request->get_param( 'is_rollback' );
		$rollback_batch_id = $request->get_param( 'rollback_batch_id' );

		$map = $this->storage->get_keyword_map( $id );

		if ( ! $map ) {
			return new WP_Error(
				'not_found',
				__( 'Keyword map not found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		// For rollback operations, skip the enabled check.
		// Check if keyword map is enabled (skip for rollback).
		if ( ! $is_rollback && ! $map->is_enabled ) {
			return new WP_Error(
				'disabled',
				__( 'This keyword map is disabled. Enable it first to execute.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		// For normal execution (not rollback), get preview results.
		$preview_results = null;
		if ( ! $is_rollback ) {
			// Get preview results BEFORE clearing them.
			$preview_processor = new Preview_Processor();
			$preview_results   = $preview_processor->get_results( $id, 1, 9999 );

			if ( is_wp_error( $preview_results ) ) {
				return $preview_results;
			}

			// Clean up preview data after retrieving it.
			$preview_processor->cancel( $id );
		}

		// Start background execution via Keyword Map Processor, passing preview data and rollback params.
		$processor = Keyword_Map_Processor::get();
		$result    = $processor->start( $map, $selected_items, $preview_results, $is_rollback, $rollback_batch_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Update execution stats (increment execution count) only for normal execution.
		if ( ! $is_rollback ) {
			$this->storage->update_execution_stats( $id );
		}

		$this->track_link_genius_event(
			'Feature Used',
			[
				'feature'              => 'Keyword Map Executed',
				'map_id'               => $id,
				'is_rollback'          => $is_rollback ? 1 : 0,
				'has_preview'          => ! empty( $preview_results ) ? 1 : 0,
				'selected_items_count' => ! empty( $selected_items ) ? count( $selected_items ) : 0,
			]
		);

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Preview keyword map execution.
	 *
	 * Generates a preview of what changes would be made without actually making them.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function preview_keyword_map( $request ) {
		$id  = (int) $request->get_param( 'id' );
		$map = $this->storage->get_keyword_map( $id );

		if ( ! $map ) {
			return new WP_Error(
				'not_found',
				__( 'Keyword map not found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		// Check if keyword map is enabled.
		if ( ! $map->is_enabled ) {
			return new WP_Error(
				'disabled',
				__( 'This keyword map is disabled. Enable it first to preview.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		// Start preview via Preview_Processor.
		$processor = new Preview_Processor();
		$result    = $processor->start( $id, $map );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$this->track_link_genius_event(
			'Feature Used',
			[
				'feature' => 'Keyword Map Preview Started',
				'map_id'  => $id,
			]
		);

		return new WP_REST_Response(
			array_merge(
				[ 'success' => true ],
				$result
			),
			200
		);
	}

	/**
	 * Get preview results with pagination.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function get_preview_results( $request ) {
		$keyword_map_id = (int) $request->get_param( 'keyword_map_id' );
		$page           = (int) $request->get_param( 'page' );
		$per_page       = (int) $request->get_param( 'per_page' );

		$processor = new Preview_Processor();
		$result    = $processor->get_results( $keyword_map_id, $page, $per_page );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( $result, 200 );
	}

	/**
	 * Get keyword map execution history.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response Response object.
	 */
	public function get_history( $request ) {
		$page     = (int) $request->get_param( 'page' );
		$per_page = (int) $request->get_param( 'per_page' );

		// Use shared History_Service for unified history retrieval.
		$history_service = new History_Service();
		return $history_service->get_history( 'keyword_map', $page, $per_page );
	}

	/**
	 * Get variations for a keyword map.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function get_variations( $request ) {
		$id  = (int) $request->get_param( 'id' );
		$map = $this->storage->get_keyword_map( $id );

		if ( ! $map ) {
			return new WP_Error(
				'not_found',
				__( 'Keyword map not found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		$variations = $this->storage->get_variations( $id );

		return new WP_REST_Response(
			[
				'success'    => true,
				'variations' => $variations,
			],
			200
		);
	}

	/**
	 * Add a variation to a keyword map.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function add_variation( $request ) {
		$id  = (int) $request->get_param( 'id' );
		$map = $this->storage->get_keyword_map( $id );

		if ( ! $map ) {
			return new WP_Error(
				'not_found',
				__( 'Keyword map not found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		$variation = sanitize_text_field( $request->get_param( 'variation' ) );
		$source    = $request->get_param( 'source' );

		// Check if variation already exists in this keyword map.
		if ( $this->storage->variation_exists( $id, $variation ) ) {
			return new WP_Error(
				'duplicate_variation',
				__( 'This variation already exists in this keyword map.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		// Check if variation exists in another keyword map (as name or variation).
		$duplicate_keyword = $this->storage->find_duplicate_keyword( $variation, $id );
		if ( $duplicate_keyword ) {
			return new WP_Error(
				'duplicate_keyword',
				sprintf(
					/* translators: %s: existing keyword map name */
					__( 'This keyword already exists in the keyword map "%s".', 'rank-math-pro' ),
					$duplicate_keyword['keyword_map']->name
				),
				[
					'status'          => 400,
					'existing_map_id' => $duplicate_keyword['keyword_map']->id,
				]
			);
		}

		$variation_id = $this->storage->add_variation( $id, $variation, $source );

		if ( ! $variation_id ) {
			return new WP_Error(
				'add_failed',
				__( 'Failed to add variation.', 'rank-math-pro' ),
				[ 'status' => 500 ]
			);
		}

		$variations = $this->storage->get_variations( $id );

		$this->track_link_genius_event(
			'Feature Used',
			[
				'feature' => 'Manual Variation Added',
				'map_id'  => $id,
				'source'  => $source,
			]
		);

		return new WP_REST_Response(
			[
				'success'      => true,
				'variation_id' => $variation_id,
				'variations'   => $variations,
				'message'      => __( 'Variation added successfully.', 'rank-math-pro' ),
			],
			201
		);
	}

	/**
	 * Delete a variation.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function delete_variation( $request ) {
		$id = (int) $request->get_param( 'id' );

		$result = $this->storage->delete_variation( $id );

		if ( ! $result ) {
			return new WP_Error(
				'delete_failed',
				__( 'Failed to delete variation.', 'rank-math-pro' ),
				[ 'status' => 500 ]
			);
		}

		$this->track_link_genius_event(
			'Button Clicked',
			[ 'button' => 'Delete Variation' ]
		);

		return new WP_REST_Response(
			[
				'success' => true,
				'message' => __( 'Variation deleted successfully.', 'rank-math-pro' ),
			],
			200
		);
	}

	/**
	 * Generate AI variations for a keyword map.
	 *
	 * Returns categorized suggestions for user to accept/reject before saving.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function generate_variations( $request ) {
		$plan = Helper::get_content_ai_plan();
		if ( ! $plan || $plan === 'free' ) {
			return new WP_Error(
				'upgrade_required',
				esc_html__( 'This feature is only available for Content AI subscribers.', 'rank-math-pro' ),
				[ 'status' => 426 ]
			);
		}

		$id  = (int) $request->get_param( 'id' );
		$map = $this->storage->get_keyword_map( $id );

		if ( ! $map ) {
			return new WP_Error(
				'not_found',
				__( 'Keyword map not found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		// Use keyword map name as the base keyword.
		$keyword = $map->name;

		// Get context from description (optional).
		$context = ! empty( $map->description ) ? $map->description : '';

		// Call AI to generate variations.
		$ai_client           = new AI_Client();
		$categorized_results = $ai_client->generate_keyword_variations( $keyword, $context );

		if ( is_wp_error( $categorized_results ) ) {
			return $categorized_results;
		}

		// Get existing variations for duplicate checking.
		$existing_variations = $this->storage->get_variations( $id );
		$existing_texts      = array_map(
			function ( $v ) {
				return strtolower( trim( $v->variation ) );
			},
			$existing_variations
		);

		// Filter out duplicates from suggestions and format for frontend.
		$suggestions       = [];
		$total_suggestions = 0;
		$category_labels   = [
			'synonyms'             => __( 'Synonyms', 'rank-math-pro' ),
			'related_phrases'      => __( 'Related Phrases', 'rank-math-pro' ),
			'long_tail_variations' => __( 'Long-tail Variations', 'rank-math-pro' ),
			'common_misspellings'  => __( 'Common Misspellings', 'rank-math-pro' ),
		];

		foreach ( $categorized_results as $category => $variations ) {
			$category_suggestions = [];
			foreach ( $variations as $variation ) {
				$variation_lower = strtolower( trim( $variation ) );
				// Skip duplicates.
				if ( in_array( $variation_lower, $existing_texts, true ) ) {
					continue;
				}
				$category_suggestions[] = [
					'text'     => $variation,
					'category' => $category,
					'selected' => true, // Default to selected.
				];
			}

			if ( ! empty( $category_suggestions ) ) {
				$total_suggestions += count( $category_suggestions );
				$suggestions[]      = [
					'category'    => $category,
					'label'       => $category_labels[ $category ] ?? $category,
					'suggestions' => $category_suggestions,
				];
			}
		}

		$this->track_link_genius_event(
			'Feature Used',
			[
				'feature'           => 'AI Variations Generated',
				'context'           => 'existing_map',
				'keyword_length'    => strlen( $keyword ),
				'has_context'       => ! empty( $context ) ? 1 : 0,
				'suggestions_count' => $total_suggestions,
				'categories_count'  => count( $suggestions ),
			]
		);

		return new WP_REST_Response(
			[
				'success'     => true,
				'suggestions' => $suggestions,
				'keyword'     => $keyword,
				'message'     => __( 'AI variations generated. Review and accept the ones you want to add.', 'rank-math-pro' ),
			],
			200
		);
	}

	/**
	 * Generate AI variations by keyword (for unsaved maps).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function generate_variations_by_keyword( $request ) {
		$keyword             = sanitize_text_field( $request->get_param( 'keyword' ) );
		$existing_variations = $request->get_param( 'existing_variations' ) ?? [];
		$context             = sanitize_text_field( $request->get_param( 'context' ) ?? '' );

		if ( empty( $keyword ) ) {
			return new WP_Error(
				'missing_keyword',
				__( 'Keyword is required.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		// Call AI to generate variations with optional context.
		$ai_client           = new AI_Client();
		$categorized_results = $ai_client->generate_keyword_variations( $keyword, $context );

		if ( is_wp_error( $categorized_results ) ) {
			return $categorized_results;
		}

		// Build existing texts array for duplicate checking.
		$existing_texts = array_map(
			function ( $v ) {
				return strtolower( trim( is_array( $v ) ? $v['variation'] : $v ) );
			},
			$existing_variations
		);

		// Filter out duplicates from suggestions and format for frontend.
		$suggestions       = [];
		$total_suggestions = 0;
		$category_labels   = [
			'synonyms'             => __( 'Synonyms', 'rank-math-pro' ),
			'related_phrases'      => __( 'Related Phrases', 'rank-math-pro' ),
			'long_tail_variations' => __( 'Long-tail Variations', 'rank-math-pro' ),
			'common_misspellings'  => __( 'Common Misspellings', 'rank-math-pro' ),
		];

		foreach ( $categorized_results as $category => $variations ) {
			$category_suggestions = [];
			foreach ( $variations as $variation ) {
				$variation_lower = strtolower( trim( $variation ) );
				// Skip duplicates.
				if ( in_array( $variation_lower, $existing_texts, true ) ) {
					continue;
				}
				$category_suggestions[] = [
					'text'     => $variation,
					'category' => $category,
					'selected' => true, // Default to selected.
				];
			}

			if ( ! empty( $category_suggestions ) ) {
				$total_suggestions += count( $category_suggestions );
				$suggestions[]      = [
					'category'    => $category,
					'label'       => $category_labels[ $category ] ?? $category,
					'suggestions' => $category_suggestions,
				];
			}
		}

		$this->track_link_genius_event(
			'Feature Used',
			[
				'feature'           => 'AI Variations Generated',
				'context'           => 'new_map',
				'keyword_length'    => strlen( $keyword ),
				'has_context'       => ! empty( $context ) ? 1 : 0,
				'suggestions_count' => $total_suggestions,
				'categories_count'  => count( $suggestions ),
			]
		);

		return new WP_REST_Response(
			[
				'success'     => true,
				'suggestions' => $suggestions,
				'keyword'     => $keyword,
				'message'     => __( 'AI variations generated. Review and accept the ones you want to add.', 'rank-math-pro' ),
			],
			200
		);
	}

	/**
	 * Accept selected AI variations.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function accept_variations( $request ) {
		$id         = (int) $request->get_param( 'id' );
		$variations = $request->get_param( 'variations' );

		$map = $this->storage->get_keyword_map( $id );

		if ( ! $map ) {
			return new WP_Error(
				'not_found',
				__( 'Keyword map not found.', 'rank-math-pro' ),
				[ 'status' => 404 ]
			);
		}

		if ( empty( $variations ) || ! is_array( $variations ) ) {
			return new WP_Error(
				'invalid_variations',
				__( 'No variations provided.', 'rank-math-pro' ),
				[ 'status' => 400 ]
			);
		}

		// Add selected variations.
		$added = 0;
		foreach ( $variations as $variation ) {
			$text = sanitize_text_field( $variation['text'] ?? $variation );
			if ( empty( $text ) ) {
				continue;
			}

			// Skip if already exists.
			if ( $this->storage->variation_exists( $id, $text ) ) {
				continue;
			}

			$result = $this->storage->add_variation( $id, $text, 'ai' );
			if ( $result ) {
				++$added;
			}
		}

		// Return updated variations list.
		$all_variations = $this->storage->get_variations( $id );

		$this->track_link_genius_event(
			'Feature Used',
			[
				'feature'   => 'AI Variations Accepted',
				'map_id'    => $id,
				'added'     => $added,
				'requested' => count( $variations ),
			]
		);

		return new WP_REST_Response(
			[
				'success'    => true,
				'added'      => $added,
				'variations' => $all_variations,
				'message'    => sprintf(
					/* translators: %d: number of variations added */
					__( '%d variations added.', 'rank-math-pro' ),
					$added
				),
			],
			200
		);
	}

	/**
	 * Format a keyword map for API response.
	 *
	 * @param object $map Keyword map object.
	 * @return array Formatted map.
	 */
	private function format_keyword_map( $map ) {
		return [
			'id'                   => (int) $map->id,
			'name'                 => $map->name,
			'description'          => $map->description,
			'target_url'           => $map->target_url,
			'is_enabled'           => (bool) $map->is_enabled,
			'max_links_per_post'   => (int) $map->max_links_per_post,
			'auto_link_on_publish' => (bool) $map->auto_link_on_publish,
			'case_sensitive'       => (bool) $map->case_sensitive,
			'created_at'           => $map->created_at,
			'updated_at'           => $map->updated_at,
			'last_executed_at'     => $map->last_executed_at,
			'execution_count'      => (int) $map->execution_count,
			'variations'           => $this->storage->get_variations( $map->id ),
			'variations_count'     => $this->storage->count_variations( $map->id ),
		];
	}

	/**
	 * Get preview endpoint arguments (required by base class).
	 *
	 * @return array Preview arguments schema.
	 */
	protected function get_preview_args() {
		// Keyword maps preview doesn't need additional args since ID is in URL.
		return [];
	}

	/**
	 * Preview method stub (required by base class but not used).
	 *
	 * Keyword maps use preview_keyword_map() instead.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function preview( $request ) {
		return new WP_Error(
			'not_implemented',
			__( 'Use preview_keyword_map endpoint instead.', 'rank-math-pro' ),
			[ 'status' => 400 ]
		);
	}

	/**
	 * Delete history method stub (required by base class but not used).
	 *
	 * Keyword maps don't support history deletion.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error Response object or error.
	 */
	public function delete_history( $request ) {
		return new WP_Error(
			'not_implemented',
			__( 'Keyword maps do not support history deletion.', 'rank-math-pro' ),
			[ 'status' => 400 ]
		);
	}
}
