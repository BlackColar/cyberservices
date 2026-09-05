<?php
namespace RankMathPro\Dependencies\Groupone\Marketplace\Controllers;

use RankMathPro\Dependencies\Groupone\Marketplace\Marketplace;
use RankMathPro\Dependencies\Groupone\Marketplace\Models\MarketplaceModel;
use RankMathPro\Dependencies\Groupone\Marketplace\Services\PluginService;
use RankMathPro\Dependencies\Groupone\Marketplace\Abilities\MarketplaceAbilities;
use RankMathPro\Dependencies\Groupone\Marketplace\Abilities\MarketplaceAbilitiesTracking;

use WP_REST_Response;
class MarketplaceController {
	protected $config;
	protected $model;
	protected $assets_base_path;
	protected $assets_base_url;

	/**
	 * Create + initialize controller instance.
	 *
	 * @param array $config
	 * @return self
	 */
	public static function boot( array $config = [] ): self {
		$instance = new self( $config );
		$instance->init();
		return $instance;
	}

	public function __construct( array $config ) {
		$this->config = wp_parse_args( $config, [
			'parent_menu_slug' => 'options-general.php',
			'page_title'       => __( 'Plugin Marketplace', 'text-domain' ),
			'menu_title'       => __( 'Marketplace', 'text-domain' ),
			'menu_slug'        => 'plugin-marketplace',
			'api_url'          => '', // default to empty, React can decide
			'brand'            => '', // optional brand identifier for marketplace API
			'css_url'          => '', //  optional additional CSS
			'css_handle'       => 'marketplace-frontend-style',
			'assets_path'      => '', //  Optional: explicit path to package root containing frontend/ directory
			'payload'          => [], //  Optional: key-value array passed as headers for API authentication
			'insert_menu_before_slug' => '', // Optional: submenu slug before which marketplace menu should appear
		] );

		// Defer model and asset initialization until needed (optimization for multi-plugin installs)
		$this->model = null;
		$this->assets_base_path = null;
		$this->assets_base_url = null;
	}

	/**
	 * Lazy-load model instance (optimization for multi-plugin installs).
	 * Only instantiated when actually needed (REST endpoint or page render).
	 */
	protected function get_model() {
		if ( $this->model === null ) {
			$is_sandbox  = ! empty( $this->config['mixp_props']['is_sandbox'] ) && $this->config['mixp_props']['is_sandbox'] === true;
			$this->model = new MarketplaceModel( $this->config['api_url'], $is_sandbox );
		}
		return $this->model;
	}

	/**
	 * Lazy-load asset paths (optimization for multi-plugin installs).
	 * Only resolved when the marketplace page is being rendered.
	 */
	protected function ensure_assets_resolved() {
		if ( $this->assets_base_path === null || $this->assets_base_url === null ) {
			$this->resolve_assets_paths();
		}
	}

	/**
	 * Resolve and validate assets paths.
	 * Priority: 1) Explicit config, 2) Auto-detect via composer.json
	 */
	protected function resolve_assets_paths() {
		$package_root = '';

		// Option 1: Use explicitly provided assets_path
		if ( ! empty( $this->config['assets_path'] ) ) {
			$package_root = wp_normalize_path( $this->config['assets_path'] );
		}

		// Option 2: Auto-detect using composer.json as anchor
		if ( empty( $package_root ) ) {
			$package_root = $this->find_package_root_via_composer();
		}

		// Validate that frontend assets actually exist
		$package_root = trailingslashit( $package_root );
		$frontend_js = $package_root . 'frontend/build/index.js';

		if ( ! file_exists( $frontend_js ) ) {
			// Last resort: use current directory (will likely fail but won't crash)
			$package_root = trailingslashit( dirname( __DIR__ ) );
		}

		$this->assets_base_path = $package_root;
		$this->assets_base_url  = $this->convert_path_to_url( $package_root );
	}

	/**
	 * Find package root by looking for composer.json
	 * Works for both Mozart-prefixed and regular vendor installations
	 *
	 * @return string Package root path or empty string
	 */
	protected function find_package_root_via_composer() {
		$current_dir = wp_normalize_path( __DIR__ );
		$max_depth = 10; // Safety limit

		for ( $i = 0; $i < $max_depth; $i++ ) {
			$composer_path = trailingslashit( $current_dir ) . 'composer.json';

			if ( file_exists( $composer_path ) ) {
				// Verify this is our package by checking the name
				$composer_data = json_decode( file_get_contents( $composer_path ), true );

				if ( isset( $composer_data['name'] ) && $composer_data['name'] === 'groupone/marketplace' ) {
					return $current_dir;
				}
			}

			// Move up one directory
			$parent_dir = dirname( $current_dir );

			// Stop if we've reached the filesystem root
			if ( $parent_dir === $current_dir ) {
				break;
			}

			$current_dir = $parent_dir;
		}

		return '';
	}

	/**
	 * Get the brand-specific AJAX action prefix to avoid conflicts when multiple
	 * plugins embed the marketplace module simultaneously.
	 *
	 * @return string e.g. 'onecom_marketplace' or 'rankmath_marketplace'
	 */
	private function get_ajax_prefix(): string {
		$brand = $this->config['brand'] ?? '';
		return $brand ? "{$brand}_marketplace" : 'marketplace';
	}

	/**
	 * Convert filesystem path to URL
	 *
	 * @param string $path Absolute filesystem path
	 * @return string URL
	 */
	protected function convert_path_to_url( $path ) {
		$path = wp_normalize_path( $path );
		$plugins_dir = wp_normalize_path( WP_PLUGIN_DIR );

		// Check if path is within plugins directory
		if ( strpos( $path, $plugins_dir ) === 0 ) {
			$relative = ltrim( str_replace( $plugins_dir, '', $path ), '/' );
			return trailingslashit( plugins_url( $relative ) );
		}

		// Fallback: try content directory
		$content_dir = wp_normalize_path( WP_CONTENT_DIR );
		if ( strpos( $path, $content_dir ) === 0 ) {
			$relative = ltrim( str_replace( $content_dir, '', $path ), '/' );
			return trailingslashit( content_url( $relative ) );
		}

		// Last resort: return plugins URL with the full path (likely incorrect but won't crash)
		return trailingslashit( plugins_url() );
	}

	/**
	 * Initialize hooks.
	 */
	public function init() {
		// Robustness: when this (Mozart-prefixed) copy is dropped in without the host
		// regenerating its autoloader classmap — e.g. the Dependencies folder copied by
		// hand — the sibling Abilities/Services classes aren't autoloadable. Load them
		// directly so the folder is self-contained. No-op on a proper composer/Mozart
		// build (the classes are already in the classmap). Paths are relative + the
		// class names resolve via the `use` aliases, so this is prefix-agnostic.
		if ( ! class_exists( PluginService::class, false ) && is_readable( __DIR__ . '/../Services/PluginService.php' ) ) {
			require_once __DIR__ . '/../Services/PluginService.php';
		}
		if ( ! class_exists( MarketplaceAbilities::class, false ) && is_readable( __DIR__ . '/../Abilities/MarketplaceAbilities.php' ) ) {
			require_once __DIR__ . '/../Abilities/MarketplaceAbilities.php';
		}
		if ( ! class_exists( MarketplaceAbilitiesTracking::class, false ) && is_readable( __DIR__ . '/../Abilities/MarketplaceAbilitiesTracking.php' ) ) {
			require_once __DIR__ . '/../Abilities/MarketplaceAbilitiesTracking.php';
		}

		if ( is_admin() || is_network_admin() ) {
			add_action( 'admin_menu', [ $this, 'register_menu' ] );
			add_action( 'admin_menu', [ $this, 'register_addons_menu' ] );
			add_action( 'network_admin_menu', [ $this, 'register_menu' ] );
			add_action( 'network_admin_menu', [ $this, 'register_addons_menu' ] );

			// If insert_menu_before_slug is configured, reorder submenus after all menus are registered.
			if ( ! empty( $this->config['insert_menu_before_slug'] ) ) {
				add_action( 'admin_menu', [ $this, 'reorder_submenus' ], 999 );
				add_action( 'network_admin_menu', [ $this, 'reorder_submenus' ], 999 );
			}
			$prefix = $this->get_ajax_prefix();
			add_action( "wp_ajax_{$prefix}_install_plugin", [ $this, 'ajax_install_plugin' ] );
			add_action( "wp_ajax_{$prefix}_activate_plugin", [ $this, 'ajax_activate_plugin' ] );
			add_action( "wp_ajax_{$prefix}_deactivate_plugin", [ $this, 'ajax_deactivate_plugin' ] );
			add_action( "wp_ajax_{$prefix}_delete_plugin", [ $this, 'ajax_delete_plugin' ] );
			add_action( "wp_ajax_{$prefix}_save_pending_procurement", [ $this, 'ajax_save_pending_procurement' ] );
			add_action( "wp_ajax_{$prefix}_clear_pending_procurement", [ $this, 'ajax_clear_pending_procurement' ] );
			add_action( "wp_ajax_{$prefix}_get_pending_procurements", [ $this, 'ajax_get_pending_procurements' ] );
			add_action( "wp_ajax_{$prefix}_subscribe", [ $this, 'ajax_subscribe' ] );
			add_action( "wp_ajax_{$prefix}_track_status", [ $this, 'ajax_track_status' ] );

			add_action( "wp_ajax_{$prefix}_get_subscriptions_list", [ $this, 'get_subscriptions_list' ] );

			add_action( "wp_ajax_{$prefix}_cancel_subscription", [ $this, 'cancel_subscriptions' ] );
			add_action( "wp_ajax_{$prefix}_unsubscribe", [ $this, 'ajax_unsubscribe' ] );
			add_action( "wp_ajax_{$prefix}_clear_subscription_list", [ $this, 'ajax_clear_subscription_list' ] );
			add_action( "wp_ajax_{$prefix}_save_pending_cancellation", [ $this, 'ajax_save_pending_cancellation' ] );
			add_action( "wp_ajax_{$prefix}_clear_pending_cancellation", [ $this, 'ajax_clear_pending_cancellation' ] );
			add_action( "wp_ajax_{$prefix}_dismiss_banner", [ $this, 'ajax_dismiss_banner' ] );


			//reset transient for marketplace catalog
			add_action('upgrader_process_complete', [$this, 'reset_transient_on_core_update'], 10, 2);
			add_action('update_option_WPLANG', [$this, 'reset_transient_on_locale_change'], 999, 0);
		}

		add_action( 'rest_api_init', [ $this, 'register_rest_routes' ] );

		// MCP usage telemetry for marketplace abilities. The token is resolved
		// here so both the frontend and the MCP tracker report into the same
		// Mixpanel project without duplicating the sandbox/production switch.
		MarketplaceAbilitiesTracking::boot(
			array_merge(
				$this->config,
				[ 'mixpanel' => [ 'token' => $this->get_mixpanel_token() ] ]
			)
		);

		// Register marketplace MCP abilities (no-op if the Abilities API isn't present).
		// The catalog provider reuses the same transient-cached fetch as the REST endpoint.
		// The host plugin's MCP server selects these abilities to expose them over MCP.
		MarketplaceAbilities::register( array_merge( $this->config, [
			'catalog_provider' => function () {
				$is_cached = false;
				return $this->fetch_catalog( $is_cached );
			},
			'subscriptions_provider' => function () {
				return $this->fetch_subscriptions();
			},
			'refresh_provider' => function () {
				return $this->refresh_catalog();
			},
			// Inputs for catalog visibility rules (mustHavePlugins / mustHaveThemesByAuthor),
			// mirroring what is localized to the frontend so MCP hides the same products.
			'visibility_provider' => function () {
				return [
					'active_plugins' => $this->get_active_plugin_slugs(),
					'theme_author'   => $this->get_active_theme_author(),
				];
			},
		] ) );
	}

	public function register_menu() {
		add_submenu_page(
			$this->config['parent_menu_slug'],
			$this->config['page_title'],
			$this->config['menu_title'],
			'manage_options',
			$this->config['menu_slug'],
			[ $this, 'render_admin_page' ]
		);
	}

	/**
	 * Register addons admin menu page with configurable slug.
	 *
	 */
	public function register_addons_menu() {
		$menu_slug      = $this->config['addons_menu_slug'] ?: 'onecom-marketplace-products';
		$has_page_title = ! empty( $this->config['addons_page_title'] );
		$has_menu_title = ! empty( $this->config['addons_menu_title'] );

		$page_title = $has_page_title ? $this->config['addons_page_title'] : __( 'Marketplace Products', '' );
		$menu_title = $has_menu_title ? $this->config['addons_menu_title'] : __( 'Your add-ons', '' );

		// When neither title is supplied, self-parent the page so it's registered without
		// a visible menu entry. WP's menu renderer only iterates $menu (top-level), so a
		// self-parented submenu never renders. Access check still passes because
		// $submenu[$menu_slug] contains our entry with its own capability.
		$parent_menu_slug = ( $has_page_title || $has_menu_title ) ? $this->config['parent_menu_slug'] : $menu_slug;

		add_submenu_page(
			$parent_menu_slug,
			$page_title,
			$menu_title,
			'manage_options',
			$menu_slug,
			[ $this, 'render_addons_page' ]
		);
	}

	/**
	 * Reorder the parent menu's submenu entries so that the marketplace page
	 * and the addons page both appear immediately before the item whose slug
	 * matches $config['insert_menu_before_slug'].
	 *
	 * Called on admin_menu / network_admin_menu at priority 999, after every
	 * other plugin has had a chance to register its own submenu items.
	 * If the target slug is not found in the submenu the array is left untouched.
	 */
	public function reorder_submenus() {
		global $submenu;

		$parent      = $this->config['parent_menu_slug'];
		$before_slug = $this->config['insert_menu_before_slug'];
		$market_slug = $this->config['menu_slug'];
		$addons_slug = $this->config['addons_menu_slug'] ?: 'onecom-marketplace-products';

		// Nothing to do if the parent has no submenu yet.
		if ( empty( $submenu[ $parent ] ) || ! is_array( $submenu[ $parent ] ) ) {
			return;
		}

		$items = array_values( $submenu[ $parent ] );

		// Check that insert_menu_before_slug exists; bail without touching anything if not.
		$before_found = false;
		foreach ( $items as $item ) {
			if ( isset( $item[2] ) && $item[2] === $before_slug ) {
				$before_found = true;
				break;
			}
		}

		if ( ! $before_found ) {
			return;
		}

		// Separate our two items from the rest, preserving their registration data.
		$market_item = null;
		$addons_item = null;
		$rest        = [];

		foreach ( $items as $item ) {
			if ( isset( $item[2] ) && $item[2] === $market_slug ) {
				$market_item = $item;
			} elseif ( isset( $item[2] ) && $item[2] === $addons_slug ) {
				$addons_item = $item;
			} else {
				$rest[] = $item;
			}
		}

		// Re-build the submenu: insert our items right before insert_menu_before_slug.
		$reordered = [];
		foreach ( $rest as $item ) {
			if ( isset( $item[2] ) && $item[2] === $before_slug ) {
				if ( $market_item !== null ) {
					$reordered[] = $market_item;
				}
				if ( $addons_item !== null ) {
					$reordered[] = $addons_item;
				}
			}
			$reordered[] = $item;
		}

		$submenu[ $parent ] = $reordered;
	}

	public function render_addons_page() {
		$this->render_marketplace_page( 'marketplace-addons-frontend', 'frontend/build/addons.js', 'marketplace-addons-root' );
	}

	public function render_admin_page() {
		$this->render_marketplace_page( 'marketplace-frontend', 'frontend/build/index.js', 'marketplace-root' );
	}

	/**
	 * Shared render pipeline for both marketplace pages. Enqueues assets,
	 * localizes the shared marketplace config, and outputs the mount-point div.
	 */
	private function render_marketplace_page( string $script_handle, string $js_file, string $root_element_id ): void {
		$this->ensure_assets_resolved();

		$base_path = $this->assets_base_path;
		$base_url  = $this->assets_base_url;

		$this->enqueue_page_assets( $script_handle, $js_file, $base_path, $base_url );

		wp_localize_script( $script_handle, 'marketplaceConfig', $this->build_marketplace_config( $base_url ) );

		$brand_class = ! empty( $this->config['brand'] ) ? ' brand-' . sanitize_html_class( $this->config['brand'] ) : '';
		echo '<div id="' . esc_attr( $root_element_id ) . '" class="gv-activated' . esc_attr( $brand_class ) . '"></div>';
	}

	/**
	 * Enqueue the page JS bundle and either the host-supplied custom CSS or the
	 * default Gravity + marketplace stylesheets.
	 */
	private function enqueue_page_assets( string $script_handle, string $js_file, string $base_path, string $base_url ): void {
		$js_path = $base_path . $js_file;
		$js_url  = $base_url . $js_file;

		wp_enqueue_script(
			$script_handle,
			$js_url,
			[ 'wp-element' ],
			file_exists( $js_path ) ? filemtime( $js_path ) : '1.0.0',
			true
		);

		if ( ! empty( $this->config['custom_css'] ) ) {
			wp_enqueue_style( 'marketplace-css', esc_url( $this->config['custom_css'] ), [], '1.0.0' );
			return;
		}

		$one_css_file = 'assets/min-css/one.min.css';
		$one_css_path = $base_path . $one_css_file;
		wp_enqueue_style(
			'marketplace-one-css',
			$base_url . $one_css_file,
			[],
			file_exists( $one_css_path ) ? filemtime( $one_css_path ) : '1.0.0'
		);

		$marketplace_css_file = 'assets/min-css/marketplace.min.css';
		$marketplace_css_path = $base_path . $marketplace_css_file;
		wp_enqueue_style(
			'marketplace-custom-css',
			$base_url . $marketplace_css_file,
			[ 'marketplace-one-css' ],
			file_exists( $marketplace_css_path ) ? filemtime( $marketplace_css_path ) : '1.0.0'
		);
	}

	/**
	 * Build the `window.marketplaceConfig` payload that gets localized into
	 * both page bundles. Pure builder — no side effects.
	 */
	private function build_marketplace_config( string $base_url ): array {
		$active_plugins      = $this->get_active_plugin_slugs();
		$active_theme_author = $this->get_active_theme_author();

		$current_user   = wp_get_current_user();
		$wp_user        = $current_user->user_login ? hash( 'sha256', $current_user->user_login ) : '';
		$wp_admin_email = $current_user->user_email ? hash( 'sha256', $current_user->user_email ) : '';
		$wp_role        = ! empty( $current_user->roles ) ? $current_user->roles[0] : '';
		$user_id        = $current_user->ID;

		$wp_version  = get_bloginfo( 'version' );
		$php_version = phpversion();
		$locale      = get_locale();

		$global_properties = [
			'application'    => 'wordpress_marketplace',
			'brand'          => $this->config['brand'],
			'wp_locale'      => $locale,
			'wp_version'     => $wp_version,
			'php_version'    => $php_version,
			'wp_user'        => $wp_user, // Hashed
			'wp_admin_email' => $wp_admin_email, // Hashed
			'wp_role'        => $wp_role,
			'user_agent'     => isset( $_SERVER['HTTP_USER_AGENT'] ) ? $_SERVER['HTTP_USER_AGENT'] : '',
			'user_id'        => $user_id,
		];

		if ( ! empty( $this->config['mixp_props'] ) && is_array( $this->config['mixp_props'] ) ) {
			$global_properties = array_merge( $global_properties, $this->config['mixp_props'] );
		}

		// is_sandbox is consumed elsewhere (model timeout); strip it before mixpanel sees it.
		if ( isset( $global_properties['is_sandbox'] ) ) {
			unset( $global_properties['is_sandbox'] );
		}

		$distinct_id         = ! empty( $this->config['mixp_distinct_id'] ) ? $this->config['mixp_distinct_id'] : '';
		$data_consent_status = ! empty( $this->config['data_consent_status'] ) ? $this->config['data_consent_status'] : false;
		$mixpanel_token      = $this->get_mixpanel_token();

		return [
			'apiBaseUrl'           => trailingslashit( rest_url( ( $this->config['brand'] ?: 'marketplace' ) . '-marketplace/v1/plugins' ) ),
			'apiUrl'               => $this->config['api_url'],
			'locale'               => $locale,
			'brand'                => $this->config['brand'],
			'useWPHandlers'        => true,
			'wpConfig'             => [
				'ajaxUrl'                  => admin_url( 'admin-ajax.php' ),
				'adminUrl'                 => admin_url(),
				'nonce'                    => wp_create_nonce( 'marketplace_nonce' ),
				'ajaxActionPrefix'         => $this->get_ajax_prefix(),
				'rankMathRegistrationSkip' => (bool) ( ! empty( get_option( 'rank_math_registration_skip' ) ) && ( get_option( 'rank_math_registration_skip' ) === '1' || get_option( 'rank_math_registration_skip' ) === true ) ),
			],
			'enableDefaultStyles'  => empty( $this->config['custom_css'] ),
			'assetsBaseUrl'        => $base_url,
			'version'              => Marketplace::VERSION,
			'wpVersion'            => $wp_version,
			'activePlugins'        => $active_plugins,
			'activeThemeAuthor'    => $active_theme_author,
			'data_consent_status'  => $data_consent_status,
			// Always send mixpanel config so it can be used when consent is granted dynamically
			'mixpanel'             => [
				'token'            => $mixpanel_token,
				'globalProperties' => $global_properties,
				'distinctId'       => $distinct_id,
			],
			'pendingProcurements'  => get_option( "{$this->config['brand']}_marketplace_pending_procurements", [] ),
			'pendingCancellations' => get_option( "{$this->config['brand']}_marketplace_pending_cancellations", [] ),
			'dismissedBanners'     => $this->get_dismissed_banners(),
			'menuSlug'             => $this->config['menu_slug'],
			'addonsMenuSlug'       => $this->config['addons_menu_slug'] ?: 'onecom-marketplace-products',
			'siteUrl'              => home_url(),
			'dateFormat'           => get_option( 'date_format', 'F j, Y' ),
		];
	}

	/**
	 * Get Mixpanel token based on sandbox mode.
	 *
	 * @return string
	 */
	protected function get_mixpanel_token(): string {
		$token = '517e881edc2636e99a2ecf013d8134d3';
		if ( ! empty( $this->config['mixp_props']['is_sandbox'] ) && $this->config['mixp_props']['is_sandbox'] === true ) {
			$token = '4cdc36e9083c158244c3e26d280540f6';
		}
		return $token;
	}

	public function register_rest_routes() {
		$brand = $this->config['brand'] ?: 'marketplace';
		$namespace = "{$brand}-marketplace/v1";

		register_rest_route( $namespace, '/plugins', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'get_plugins' ],
			'permission_callback' => '__return_true',
		] );

		register_rest_route( $namespace, '/plugins/active/(?P<slug>[a-zA-Z0-9-_]+)', [
			'methods'             => 'GET',
			'callback'            => [ $this, 'check_plugin_activation' ],
			'permission_callback' => '__return_true',
		] );

	}

	public function check_plugin_activation( $request ) {
		$slug = $request->get_param( 'slug' );
		if ( empty( $slug ) ) {
			return new WP_REST_Response( [ 'activated' => false, 'error' => 'Missing slug' ], 400 );
		}

		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin_file = $this->resolve_plugin_file_by_slug( $slug );

		$activated = ( ! empty( $plugin_file ) && function_exists( 'is_plugin_active' ) ) ? is_plugin_active( $plugin_file ) : false;

		return new WP_REST_Response( [
			'slug'      => $slug,
			'activated' => $activated,
		], 200 );
	}


	/**
	 * Clear the marketplace caches (catalog + subscriptions) and re-fetch the catalog
	 * fresh from the API. Shared by the MCP refresh-products ability.
	 *
	 * @return array|\WP_Error Fresh catalog payload, or WP_Error on fetch failure.
	 */
	public function refresh_catalog() {
		$brand_name = $this->config['brand'];
		delete_site_transient( "{$brand_name}_marketplace_catalog" );
		delete_site_transient( "{$brand_name}_subscription_list" );

		// Cache just cleared, so this hits the API and re-primes the transient.
		$is_cached = false;
		return $this->fetch_catalog( $is_cached );
	}

	/**
	 * Fetch the marketplace catalog (transient-cached, brand-specific).
	 * Shared by the REST endpoint (get_plugins) and the MCP list-products ability,
	 * so the upstream fetch + 15-minute cache logic lives in one place.
	 *
	 * @param bool $is_cached Set by reference to whether the result came from cache.
	 * @return array|\WP_Error  Raw catalog payload, or WP_Error on fetch/structure failure.
	 *                          Maintenance-mode payloads are returned as-is (caller must check
	 *                          $payload['data']['maintenanceMode']).
	 */
	public function fetch_catalog( &$is_cached = false ) {
		$brand_name = $this->config['brand'];
		$transient_name = "{$brand_name}_marketplace_catalog";
		$marketplace_catalog = get_site_transient( $transient_name );
		$is_cached = false;

		// Maintenance-mode passthrough from cache.
		if ( is_array( $marketplace_catalog ) && ! empty( $marketplace_catalog['data']['maintenanceMode'] ) ) {
			$is_cached = true;
			return $marketplace_catalog;
		}

		if ( is_array( $marketplace_catalog ) &&
			! empty( $marketplace_catalog['success'] ) &&
			isset( $marketplace_catalog['data']['catalog'] ) &&
			is_array( $marketplace_catalog['data']['catalog'] )
		){
			$is_cached = true;
			return $marketplace_catalog;
		}

		// Lazy-load model only when actually fetching (optimization)
		$catalog_payload = $this->config['payload'] ?? [];

		// onecom's partner API doesn't expect an 'action' key — strip it for that brand.
		if ( 'onecom' === ( $this->config['brand'] ?? '' ) ) {
			unset( $catalog_payload['action'] );
		}

		$plugins = $this->get_model()->fetch_plugins( $catalog_payload );

		if ( is_wp_error( $plugins ) ) {
			return $plugins;
		}

		// Maintenance-mode passthrough from upstream. Intentionally NOT cached:
		// every Retry click triggers a fresh upstream check so users recover as soon
		// as the API is back, instead of waiting for a transient to expire.
		if ( is_array( $plugins ) && ! empty( $plugins['data']['maintenanceMode'] ) ) {
			return $plugins;
		}

		// Cache the catalog for 15 minutes
		if (
			! empty( $plugins['success'] ) &&
			isset( $plugins['data']['catalog'] ) &&
			is_array( $plugins['data']['catalog'] )
		){
			set_site_transient( $transient_name, $plugins, 15 * MINUTE_IN_SECONDS );
		} else {
			error_log( 'Invalid catalog structure' );
			return new \WP_Error( 'invalid_catalog', 'Invalid catalog structure' );
		}

		return $plugins;
	}

	public function get_plugins( $request ) {

		$is_cached = false;
		$plugins = $this->fetch_catalog( $is_cached );

		if ( is_wp_error( $plugins ) ) {
			return new WP_REST_Response( [ 'error' => $plugins->get_error_message() ], 500 );
		}

		// Maintenance-mode passthrough: render as-is, skip enrichment.
		if ( is_array( $plugins ) && ! empty( $plugins['data']['maintenanceMode'] ) ) {
			return new WP_REST_Response( $plugins, 200 );
		}

		// Attach WP state (installed/activated) for both legacy and new shapes
		$add_state = function( $plugin ) {
			if ( empty( $plugin['slug'] ) ) {
				return $plugin;
			}
			// Check if plugin is installed
			$plugin['installed'] = $this->is_installed( $plugin['slug'] );

			// Only resolve plugin file if we need to check activation status
			$plugin['activated'] = false;
			if ( $plugin['installed'] ) {
				$plugin_file = $this->resolve_plugin_file_by_slug( $plugin['slug'] );
				$plugin['activated'] = ( ! empty( $plugin_file ) && function_exists( 'is_plugin_active' ) ) ? is_plugin_active( $plugin_file ) : false;
			}

			return $plugin;
		};

		if ( ! empty( $plugins['data']['catalog'] ) && is_array( $plugins['data']['catalog'] ) ) {
			// New API response structure: data.catalog array
			$plugins['data']['catalog'] = array_map( $add_state, $plugins['data']['catalog'] );
		} elseif ( isset( $plugins['data'] ) && is_array( $plugins['data'] ) && ( array_values( $plugins['data'] ) === $plugins['data'] ) ) {
			// data is a numerically-indexed list of plugins
			$plugins['data'] = array_map( $add_state, $plugins['data'] );
		} elseif ( ! empty( $plugins['data']['sections'] ) && is_array( $plugins['data']['sections'] ) ) {
			foreach ( $plugins['data']['sections'] as $si => $section ) {
				if ( empty( $section['items'] ) || ! is_array( $section['items'] ) ) {
					continue;
				}
				$plugins['data']['sections'][$si]['items'] = array_map( $add_state, $section['items'] );
			}
		} elseif ( ! empty( $plugins['sections'] ) && is_array( $plugins['sections'] ) ) {
			foreach ( $plugins['sections'] as $si => $section ) {
				if ( empty( $section['items'] ) || ! is_array( $section['items'] ) ) {
					continue;
				}
				$plugins['sections'][$si]['items'] = array_map( $add_state, $section['items'] );
			}
 	} elseif ( ! empty( $plugins['data']['ui_json'] ) && is_array( $plugins['data']['ui_json'] ) ) {
 		$plugins['data']['ui_json'] = array_map( $add_state, $plugins['data']['ui_json'] );
 	}

 	// Add is_cached flag to response
 	$plugins['is_cached'] = $is_cached;

 	return new WP_REST_Response( $plugins, 200 );
	}

	/**
	 * Install plugin via WP_Upgrader
	 */
	public function ajax_install_plugin() {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error([ 'message' => 'Permission denied' ]);
		}

		$slug         = sanitize_text_field( $_REQUEST['slug'] ?? '' );
		$download_url = esc_url_raw( $_REQUEST['download_url'] ?? '' );

		$result = PluginService::install( $slug, $download_url );
		$this->send_service_result( $result );
	}

	/**
	 * Get the author of the currently active theme.
	 *
	 * @return string Theme author or empty string if not available.
	 */
	private function get_active_theme_author(): string {
		if ( ! function_exists( 'wp_get_theme' ) ) {
			return '';
		}

		$theme = wp_get_theme();
		$author = $theme->get( 'Author' );

		return is_string( $author ) ? $author : '';
	}

	/**
	 * Get all active plugin slugs on the site.
	 * Extracts slugs from active plugin paths (e.g., 'plugin-dir/plugin-file.php' -> 'plugin-dir').
	 * For single-file plugins, the slug is the filename without .php extension.
	 *
	 * @return array Array of active plugin slugs.
	 */
	private function get_active_plugin_slugs(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return [];
		}

		$active_plugins = get_option( 'active_plugins', [] );
		$slugs = [];

		foreach ( $active_plugins as $plugin_path ) {
			// Plugin path is like 'plugin-dir/plugin-file.php' or 'single-file-plugin.php'
			if ( strpos( $plugin_path, '/' ) !== false ) {
				// Multi-file plugin: extract directory name as slug
				$parts = explode( '/', $plugin_path );
				$slugs[] = $parts[0];
			} else {
				// Single-file plugin: use filename without .php as slug
				$slugs[] = str_replace( '.php', '', $plugin_path );
			}
		}

		// Remove duplicates and return
		return array_values( array_unique( $slugs ) );
	}

	/**
	 * Check if plugin is installed.
	 *
	 * This function checks whether a plugin is physically installed in the WordPress plugins directory.
	 * It handles both simple directory slugs (e.g., 'akismet') and full plugin file paths
	 * (e.g., 'seo-by-rank-math-pro/rank-math-pro.php').
	 *
	 * For cases where the slug doesn't match the directory name exactly (e.g., slug "rank-math-pro"
	 * but installed as "seo-by-rank-math-pro/rank-math-pro.php"), the function will scan installed
	 * plugins to find matches based on the main plugin file name.
	 *
	 * @param string $slug Plugin slug or plugin file path (e.g., 'akismet' or 'dirname/filename.php').
	 * @return boolean True if the plugin is installed, false otherwise.
	 */
	private function is_installed( $slug = '' ): bool {
		return PluginService::is_installed( (string) $slug );
	}

	/**
	 * Resolve the plugin's main file by slug by scanning installed plugins.
	 * Handles cases like 'seo-by-rank-math/rank-math.php' where the main file
	 * does not match slug/slug.php.
	 *
	 * For cases where the slug doesn't match the directory name exactly (e.g., slug "rank-math-pro"
	 * but installed as "seo-by-rank-math-pro/rank-math-pro.php"), the function will scan installed
	 * plugins to find matches based on the main plugin file name.
	 *
	 * @param string $slug
	 * @return string Plugin file path relative to plugins dir, or empty string if not found.
	 */
	private function resolve_plugin_file_by_slug( $slug ): string {
		return PluginService::resolve_plugin_file_by_slug( (string) $slug );
	}

	public function ajax_activate_plugin() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( [ 'message' => __( 'You do not have permission to activate plugins.', 'onecom-wp' ) ] );
		}

		check_ajax_referer( 'marketplace_nonce', '_wpnonce' );

		$slug = isset( $_REQUEST['slug'] ) ? sanitize_key( wp_unslash( $_REQUEST['slug'] ) ) : '';

		$result = PluginService::activate( $slug );

		// After a successful activation the plugin's own options exist; write any license
		// details we staged for this slug so they merge into the plugin's own option.
		if ( ! empty( $result['success'] ) ) {
			$this->apply_pending_license( $slug );
		}

		$this->send_service_result( $result );
	}

	public function ajax_deactivate_plugin() {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error([ 'message' => 'Permission denied' ]);
		}

		$slug = sanitize_text_field( $_REQUEST['slug'] ?? '' );

		$result = PluginService::deactivate( $slug );
		$this->send_service_result( $result );
	}

	public function ajax_delete_plugin() {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		if ( ! current_user_can( 'delete_plugins' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied' ] );
		}

		$slug = sanitize_text_field( $_REQUEST['slug'] ?? '' );

		$result = PluginService::delete( $slug );
		$this->send_service_result( $result );
	}

	/**
	 * Map a PluginService normalized result to a JSON AJAX response.
	 * Preserves the legacy payload shape (message + installed/activated flags)
	 * the React frontend relies on, plus the machine-readable `code` the frontend
	 * maps to localized notification strings (ERROR_CODE_TO_I18N_KEY).
	 */
	private function send_service_result( array $result ): void {
		$payload = [
			'code'      => $result['code'],
			'message'   => $result['message'],
			'installed' => $result['installed'],
			'activated' => $result['activated'],
		];

		if ( $result['success'] ) {
			wp_send_json_success( $payload );
		}

		wp_send_json_error( $payload );
	}

	/**
	 * Resets the transient on core update.
	 * @param $upgrader
	 * @param $hook_extra
	 * @return void
	 */
	public function reset_transient_on_core_update($upgrader, $hook_extra): void
	{
		if (
			empty( $hook_extra['action'] ) || 'update' !== $hook_extra['action'] ||
			empty( $hook_extra['type'] )   || 'core' !== $hook_extra['type']
		) {
			return;
		}

		$brand_name = $this->config['brand'];
		$transient_name = "{$brand_name}_marketplace_catalog";
		$deleted = delete_site_transient( $transient_name );

		if ( $deleted ) {
			error_log( 'Reset marketplace catalog transient on core update' );
		}
	}

	/**
	 * Resets the marketplace catalog transient by deleting it from the site transients on locale change.
	 *
	 * @return void
	 */
	public function reset_transient_on_locale_change(){
		$brand_name = $this->config['brand'];
		$transient_name = "{$brand_name}_marketplace_catalog";
		$deleted = delete_site_transient( $transient_name );

		if ( $deleted ) {
			error_log( 'Reset marketplace catalog transient on locale change' );
		}
	}

	/**
	 * Save a pending procurement entry for a plugin.
	 * Called when subscription API returns success but no accessUrl yet.
	 */
	public function ajax_save_pending_procurement() {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		$slug            = sanitize_text_field( $_POST['slug'] ?? '' );
		$subscription_id = sanitize_text_field( $_POST['subscriptionId'] ?? '' );
		$product_id      = sanitize_text_field( $_POST['product_id'] ?? '' );

		if ( empty( $slug ) || empty( $subscription_id ) ) {
			wp_send_json_error( [ 'message' => 'Missing required fields.' ] );
		}

		$brand_name = $this->config['brand'];
		$option_name = "{$brand_name}_marketplace_pending_procurements";
		$pending = get_option( $option_name, [] );

		$pending[ $slug ] = [
			'subscriptionId' => $subscription_id,
			'product_id'     => $product_id,
			'timestamp'      => time(),
		];

		update_option( $option_name, $pending, false );

		wp_send_json_success( [ 'message' => 'Pending procurement saved.' ] );
	}

	/**
	 * Return the current pending procurements from the DB.
	 * Called by the frontend refresh button to re-sync React state with the server.
	 */
	public function ajax_get_pending_procurements(): void {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		$brand_name = $this->config['brand'];
		$pending    = get_option( "{$brand_name}_marketplace_pending_procurements", [] );

		wp_send_json_success( $pending );
	}

	/**
	 * Proxy a subscription creation request to the external marketplace API.
	 * Avoids CORS issues by making the request server-side and keeps the API key out of the browser.
	 */
	public function ajax_subscribe() {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied' ] );
		}

		$product_id     = sanitize_text_field( $_POST['productId'] ?? '' );
		$price_amount   = floatval( $_POST['priceAmount'] ?? 0 );
		$price_currency = sanitize_text_field( $_POST['priceCurrency'] ?? '' );
		$price_period   = sanitize_text_field( $_POST['pricePeriod'] ?? '' );

		if ( empty( $product_id ) ) {
			wp_send_json_error( [ 'message' => 'Missing required fields.' ] );
		}

		$data = [
			'productId' => $product_id,
			'price'     => $price_amount,
			'currency'  => $price_currency,
			'interval'  => $price_period,
		];

		// Merge config credentials (username, api_key, locale) with subscribe-specific fields.
		// 'action' overwrites the one in config['payload'] since it comes second in array_merge.
		$payload = array_merge(
			$this->config['payload'] ?? [],
			[
				'action' => 'wp-marketplace-subscribe',
				'data'   => wp_json_encode( $data ),
			]
		);

		// onecom's partner API doesn't expect an 'action' key — strip it for that brand.
		if ( 'onecom' === ( $this->config['brand'] ?? '' ) ) {
			unset( $payload['action'] );
		}

		$result = $this->get_model()->request( $payload, 'POST' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		if ( isset( $result['error'] ) && $result['error'] ) {
			wp_send_json_error( $result );
		}

		// Immediate-active purchases return the license inline — stage it here (it is
		// written on activation, see ajax_activate_plugin). Slug is carried on our own
		// request. The pending -> active poll path is handled in ajax_track_status.
		$slug         = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$status       = $result['data']['status'] ?? '';
		$license_data = $result['data']['license']['licenseData'] ?? null;
		if ( 'active' === $status && '' !== $slug && is_array( $license_data ) && ! empty( $license_data ) ) {
			$this->stage_license( $slug, $license_data );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Poll the external API to track subscription/procurement status.
	 * Proxies wp-marketplace-track-status calls server-side to keep credentials out of browser.
	 */
	public function ajax_track_status() {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( [ 'message' => 'Permission denied' ] );
		}

		$subscription_id = sanitize_text_field( $_POST['subscriptionId'] ?? '' );
		$resource_type   = sanitize_text_field( $_POST['resourceType'] ?? 'procurement' );

		if ( empty( $subscription_id ) ) {
			wp_send_json_error( [ 'message' => 'Missing subscriptionId.' ] );
		}

		// SSO login: ask the status endpoint for a fresh single-sign-on URL for this
		// subscription and return only the link (the frontend opens it in a new tab).
		$type = sanitize_text_field( wp_unslash( $_POST['type'] ?? '' ) );
		if ( 'get_sso_url' === $type ) {
			$sso_payload = array_merge(
				$this->config['payload'] ?? [],
				[
					'action'        => 'wp-marketplace-track-status',
					// The status endpoint dispatches on resource_type (it echoed our
					// 'procurement' back as data.type and returned status, not the SSO URL).
					// Select the SSO operation via resource_type; also send `type` in case the
					// backend keys on that instead.
					'resource_type' => 'get_sso_url',
					'type'          => 'get_sso_url',
					'resource_id'   => $subscription_id,
				]
			);
			if ( 'onecom' === ( $this->config['brand'] ?? '' ) ) {
				unset( $sso_payload['action'] );
			}

			$sso_result = $this->get_model()->request( $sso_payload, 'POST' );
			if ( is_wp_error( $sso_result ) ) {
				wp_send_json_error( [ 'message' => $sso_result->get_error_message() ] );
			}
			if ( isset( $sso_result['error'] ) && $sso_result['error'] ) {
				wp_send_json_error( $sso_result );
			}
			// Tolerate common response shapes: data.ssoUrl (documented), top-level ssoUrl,
			// double-nested data.data.ssoUrl, and snake_case / generic url keys.
			$sso_url = $sso_result['data']['ssoUrl']
				?? $sso_result['ssoUrl']
				?? $sso_result['data']['data']['ssoUrl']
				?? $sso_result['data']['sso_url']
				?? $sso_result['data']['url']
				?? '';
			if ( '' === $sso_url ) {
				wp_send_json_error( [ 'message' => 'No SSO URL returned.' ] );
			}
			wp_send_json_success( [ 'ssoUrl' => esc_url_raw( $sso_url ) ] );
		}

		$payload = array_merge(
			$this->config['payload'] ?? [],
			[
				'action'        => 'wp-marketplace-track-status',
				'resource_type' => $resource_type,
				'resource_id'   => $subscription_id,
			]
		);

		// onecom's partner API doesn't expect an 'action' key — strip it for that brand.
		if ( 'onecom' === ( $this->config['brand'] ?? '' ) ) {
			unset( $payload['action'] );
		}

		$result = $this->get_model()->request( $payload, 'POST' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		if ( isset( $result['error'] ) && $result['error'] ) {
			wp_send_json_error( $result );
		}

		// Data-driven license provisioning: once the procurement is active, STAGE the
		// license for this plugin. It's written to the DB only after the plugin is
		// activated (see ajax_activate_plugin -> apply_pending_license), so the plugin's
		// own activation routine can't overwrite it. The slug is carried on our own
		// request (not from the external API) — see the FE track-status call.
		$slug         = sanitize_key( wp_unslash( $_POST['slug'] ?? '' ) );
		$status       = $result['data']['status'] ?? '';
		$license_data = $result['data']['license']['licenseData'] ?? null;
		if ( 'active' === $status && '' !== $slug && is_array( $license_data ) && ! empty( $license_data ) ) {
			$this->stage_license( $slug, $license_data );
		}

		wp_send_json_success( $result );
	}

	/**
	 * Option name holding staged (pending) licenses, keyed by plugin slug.
	 */
	private function pending_licenses_option(): string {
		$brand = $this->config['brand'] ?? '';
		return "{$brand}_marketplace_pending_licenses";
	}

	/**
	 * Stage a plugin's license for provisioning on activation.
	 *
	 * We do NOT write to the target option here — some plugins reinitialise their
	 * options in their activation routine, which would clobber an early write. Instead
	 * we persist the licenseData keyed by slug and apply it right after the plugin is
	 * activated (see apply_pending_license). If the plugin is already active, apply now
	 * (its activation routine has already run, and activation won't fire again).
	 *
	 * @param string $slug         Plugin slug (our correlation key).
	 * @param array  $license_data Response licenseData map.
	 */
	private function stage_license( string $slug, array $license_data ): void {
		if ( '' === $slug || empty( $license_data ) ) {
			return;
		}

		// Encrypt sensitive values (e.g. the SocialPilot API key) with the target plugin's
		// at-rest scheme the moment we receive them, so plaintext is never written to the
		// DB — not to the staged pending option, nor to the plugin's own option.
		$license_data = $this->encrypt_license_data_for_storage( $license_data );
		if ( empty( $license_data ) ) {
			return;
		}

		if ( $this->is_plugin_active_by_slug( $slug ) ) {
			$this->apply_license_data( $license_data );
			return;
		}

		$option  = $this->pending_licenses_option();
		$pending = get_option( $option, [] );
		if ( ! is_array( $pending ) ) {
			$pending = [];
		}
		$pending[ $slug ] = $license_data;
		update_option( $option, $pending, false );
	}

	/**
	 * Apply (and clear) a staged license for the given slug. Called from
	 * ajax_activate_plugin right after the plugin is activated.
	 *
	 * @param string $slug
	 */
	private function apply_pending_license( string $slug ): void {
		if ( '' === $slug ) {
			return;
		}
		$option  = $this->pending_licenses_option();
		$pending = get_option( $option, [] );
		if ( ! is_array( $pending ) || empty( $pending[ $slug ] ) || ! is_array( $pending[ $slug ] ) ) {
			return;
		}

		$this->apply_license_data( $pending[ $slug ] );

		unset( $pending[ $slug ] );
		update_option( $option, $pending, false );
	}

	/**
	 * Stage licenses for every active subscription that carries licenseData. Slugs are
	 * resolved from productId via the cached catalog.
	 *
	 * @param mixed $subscriptions
	 */
	private function stage_licenses_from_subscriptions( $subscriptions ): void {
		if ( ! is_array( $subscriptions ) ) {
			return;
		}
		$map = null;
		foreach ( $subscriptions as $sub ) {
			if ( ! is_array( $sub ) || 'active' !== ( $sub['status'] ?? '' ) ) {
				continue;
			}
			$license = $sub['licenseData'] ?? null;
			if ( ! is_array( $license ) || empty( $license ) ) {
				continue;
			}
			$product_id = (string) ( $sub['productId'] ?? '' );
			if ( '' === $product_id ) {
				continue;
			}
			if ( null === $map ) {
				$map = $this->product_id_to_slug_map();
			}
			$slug = $map[ $product_id ] ?? '';
			if ( '' !== $slug ) {
				$this->stage_license( $slug, $license );
			}
		}
	}

	/**
	 * Build a productId => slug map from the cached marketplace catalog. Best-effort:
	 * returns an empty map if the catalog isn't cached (the track-status path still
	 * stages by the slug carried on its request).
	 *
	 * @return array<string,string>
	 */
	private function product_id_to_slug_map(): array {
		$brand   = $this->config['brand'] ?? '';
		$catalog = get_site_transient( "{$brand}_marketplace_catalog" );
		if ( ! is_array( $catalog ) ) {
			return [];
		}

		$items = [];
		if ( ! empty( $catalog['data']['catalog'] ) && is_array( $catalog['data']['catalog'] ) ) {
			$items = $catalog['data']['catalog'];
		} elseif ( isset( $catalog['data'] ) && is_array( $catalog['data'] ) && array_values( $catalog['data'] ) === $catalog['data'] ) {
			$items = $catalog['data'];
		} elseif ( ! empty( $catalog['data']['ui_json'] ) && is_array( $catalog['data']['ui_json'] ) ) {
			$items = $catalog['data']['ui_json'];
		} else {
			$sections = $catalog['data']['sections'] ?? $catalog['sections'] ?? [];
			if ( is_array( $sections ) ) {
				foreach ( $sections as $section ) {
					if ( ! empty( $section['items'] ) && is_array( $section['items'] ) ) {
						$items = array_merge( $items, $section['items'] );
					}
				}
			}
		}

		$map = [];
		foreach ( $items as $item ) {
			if ( is_array( $item ) && ! empty( $item['slug'] ) && ! empty( $item['productId'] ) ) {
				$map[ (string) $item['productId'] ] = (string) $item['slug'];
			}
		}
		return $map;
	}

	/**
	 * Whether the plugin resolved from a slug is currently active.
	 *
	 * @param string $slug
	 * @return bool
	 */
	private function is_plugin_active_by_slug( string $slug ): bool {
		if ( '' === $slug ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		$plugin_file = $this->resolve_plugin_file_by_slug( $slug );
		return ! empty( $plugin_file ) && function_exists( 'is_plugin_active' ) && is_plugin_active( $plugin_file );
	}

	/**
	 * Data-driven license provisioning.
	 *
	 * Writes license values returned by the purchase/procurement API into the WP
	 * database, driven entirely by the API response — new plugins need no module
	 * code, only the right `licenseData` in their response. Each entry is:
	 *
	 *     { "key": [ "<option_name>", "<nested>", ... ], "value": "<storage-ready>" }
	 *
	 * The first `key` element is the wp_option name; the rest is the nested path within
	 * that option's array. Values are written verbatim: sensitive ones (e.g. SocialPilot's
	 * key) were already encrypted at stage time (see encrypt_license_data_for_storage), so
	 * the plaintext never reaches this point.
	 *
	 * @param array $license_data Map/list of { key: string[], value: mixed } entries.
	 */
	private function apply_license_data( array $license_data ): void {
		foreach ( $license_data as $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['key'] ) || ! is_array( $entry['key'] ) || ! array_key_exists( 'value', $entry ) ) {
				continue;
			}
			$this->set_option_by_path( array_values( $entry['key'] ), $entry['value'] );
		}
	}

	/**
	 * Encrypt sensitive license values at stage time so the plaintext key never lands in
	 * the DB — not even the staged pending option. The API delivers the SocialPilot key
	 * as plaintext over authenticated HTTPS; here we immediately encrypt it with
	 * SocialPilot's own at-rest scheme (site-local, salt-derived) and keep only the
	 * ciphertext. Which options need this is filterable via
	 * `marketplace_license_reencrypt_options` (default: `socialpilot_options`); other
	 * products' values are left untouched (stored verbatim). If encryption fails the entry
	 * is DROPPED rather than persisted as plaintext.
	 *
	 * @param array $license_data Map/list of { key: string[], value: mixed } entries.
	 * @return array Same structure, with sensitive values replaced by ciphertext.
	 */
	private function encrypt_license_data_for_storage( array $license_data ): array {
		$reencrypt_options = apply_filters( 'marketplace_license_reencrypt_options', [ 'socialpilot_options' ] );

		foreach ( $license_data as $k => $entry ) {
			if ( ! is_array( $entry ) || empty( $entry['key'] ) || ! is_array( $entry['key'] ) || ! array_key_exists( 'value', $entry ) ) {
				continue;
			}
			$option_name = (string) ( $entry['key'][0] ?? '' );
			if ( ! in_array( $option_name, (array) $reencrypt_options, true ) ) {
				continue; // Not sensitive — store verbatim.
			}
			$value = $entry['value'];
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			$encrypted = $this->socialpilot_encrypt( $value );
			// Scrub the plaintext from the array + local before continuing.
			$license_data[ $k ]['value'] = '';
			$value                       = '';

			if ( is_string( $encrypted ) && '' !== $encrypted ) {
				$license_data[ $k ]['value'] = $encrypted;
			} else {
				// Encryption failed: drop the entry rather than store a plaintext/empty key.
				error_log( '[Marketplace] license encryption failed; dropped entry for ' . $option_name );
				unset( $license_data[ $k ] );
			}
		}
		return $license_data;
	}

	/**
	 * Encrypt a plaintext key with SocialPilot's own site-local scheme. Prefers the
	 * plugin's SocialPilot_Encrypt::encrypt() (guarantees exact key derivation + envelope,
	 * available once the plugin is active); falls back to replicating its scheme when the
	 * class isn't loaded (constant if defined, else salt-derived key).
	 *
	 * @param string $plaintext
	 * @return string|false base64( IV ‖ ciphertext ), or false on failure.
	 */
	private function socialpilot_encrypt( string $plaintext ) {
		if ( class_exists( 'SocialPilot_Encrypt' ) ) {
			try {
				$encrypted = \SocialPilot_Encrypt::encrypt( $plaintext );
				if ( is_string( $encrypted ) && '' !== $encrypted ) {
					return $encrypted;
				}
			} catch ( \Throwable $e ) {
				error_log( '[Marketplace] SocialPilot_Encrypt::encrypt failed, using fallback: ' . $e->getMessage() );
			}
		}

		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return false;
		}
		// Replicate SocialPilot_Encrypt::get_encryption_key(): explicit constant, else a
		// 32-char (=32-byte) salt-derived key. Keep this in sync with the plugin's scheme.
		$site_key = defined( 'SOCIALPILOT_ENCRYPTION_KEY' )
			? (string) SOCIALPILOT_ENCRYPTION_KEY
			: wp_hash( 'socialpilot_' . AUTH_KEY . AUTH_SALT, 'nonce' );
		$iv        = openssl_random_pseudo_bytes( 16 );
		$encrypted = openssl_encrypt( $plaintext, 'aes-256-cbc', $site_key, OPENSSL_RAW_DATA, $iv );
		if ( false === $encrypted ) {
			return false;
		}
		return base64_encode( $iv . $encrypted );
	}

	/**
	 * Set a value at an option path: the first element is the option name, the rest is
	 * the nested array path inside it. Guarded so an API response can't overwrite core
	 * WordPress options.
	 *
	 * @param array $path  [ option_name, ...nested_keys ]
	 * @param mixed $value Value to store (verbatim).
	 * @return bool Whether the option was updated.
	 */
	private function set_option_by_path( array $path, $value ): bool {
		$option_name = (string) array_shift( $path );

		if ( '' === $option_name || ! $this->is_writable_option( $option_name ) ) {
			error_log( '[Marketplace] license write blocked for option: ' . $option_name );
			return false;
		}

		// Top-level option.
		if ( empty( $path ) ) {
			return $this->update_option_verbatim( $option_name, $value );
		}

		// Nested value inside the option's array; create intermediate containers as needed.
		$data = get_option( $option_name, [] );
		if ( ! is_array( $data ) ) {
			$data = [];
		}

		$cursor = &$data;
		$last   = array_pop( $path );
		foreach ( $path as $segment ) {
			if ( ! isset( $cursor[ $segment ] ) || ! is_array( $cursor[ $segment ] ) ) {
				$cursor[ $segment ] = [];
			}
			$cursor = &$cursor[ $segment ];
		}
		$cursor[ $last ] = $value;
		unset( $cursor );

		return $this->update_option_verbatim( $option_name, $data );
	}

	/**
	 * Write an option verbatim, suppressing its registered sanitize_callback for this
	 * one write. License values are already in their final at-rest form, so a plugin's
	 * sanitizer must not re-process them — e.g. SocialPilot registers a sanitize callback
	 * (via register_setting) that re-encrypts `api_key` on EVERY update_option, which
	 * would double-encrypt the value we stored (SocialPilot then decrypts once and gets
	 * ciphertext, not the key). We save/restore the exact hook so nothing else is affected.
	 *
	 * @param string $option
	 * @param mixed  $value
	 * @return bool
	 */
	private function update_option_verbatim( string $option, $value ): bool {
		global $wp_filter;
		$hook  = "sanitize_option_{$option}";
		$saved = $wp_filter[ $hook ] ?? null;
		if ( null !== $saved ) {
			unset( $wp_filter[ $hook ] );
		}
		// finally: always restore the sanitize hook, even if update_option() throws —
		// otherwise it stays removed for the rest of the request.
		try {
			return update_option( $option, $value );
		} finally {
			if ( null !== $saved ) {
				$wp_filter[ $hook ] = $saved;
			}
		}
	}

	/**
	 * Guard which options the license writer may touch. Allowlist-based: only options a
	 * product explicitly opts into may be written, so a compromised API response can't
	 * name an arbitrary option (a denylist can't guarantee this — it always misses cases
	 * like sidebars_widgets, theme_mods_*, uninstall_plugins, third-party options).
	 *
	 * @param string $option_name
	 * @return bool
	 */
	private function is_writable_option( string $option_name ): bool {
		/**
		 * Allowlist of options the license provisioner may write. Defaults to SocialPilot's
		 * option (the sole consumer today); onboard a new product by adding its option here.
		 * Mirrors the default of `marketplace_license_reencrypt_options`.
		 *
		 * @param string[] $allowed_options
		 */
		$allowed_options = (array) apply_filters(
			'marketplace_license_writable_options',
			[ 'socialpilot_options' ]
		);
		$allowed = in_array( $option_name, $allowed_options, true );

		/**
		 * Per-name veto retained so a host can further block a specific allowlisted name.
		 *
		 * @param bool   $allowed
		 * @param string $option_name
		 */
		return (bool) apply_filters( 'marketplace_license_writable_option', $allowed, $option_name );
	}

	/**
	 *
	 * @return void
	 */
	/**
	 * Fetch the site's marketplace subscription list (transient-cached, brand-specific).
	 * Shared by the AJAX endpoint (get_subscriptions_list) and the MCP list-subscriptions
	 * ability, so the upstream fetch + 15-minute cache lives in one place.
	 *
	 * @return array|\WP_Error List of subscriptions, or WP_Error on fetch/API failure.
	 */
	public function fetch_subscriptions() {
		// onecom uses a separate per-plugin purchase check, not the marketplace
		// subscription-list API (which its partner API rejects with "Validation Failed").
		// Mirror get_subscriptions_list() and return an empty list for that brand.
		if ( 'onecom' === ( $this->config['brand'] ?? '' ) ) {
			return [];
		}

		$brand_name     = $this->config['brand'];
		$transient_name = "{$brand_name}_subscription_list";
		$cached         = get_site_transient( $transient_name );

		if ( is_array( $cached ) && ! empty( $cached ) ) {
			return $cached;
		}

		$payload = array_merge(
			$this->config['payload'] ?? [],
			[ 'action' => 'wp-marketplace-subscription-list' ]
		);

		// The default method is GET.
		$result = $this->get_model()->request( $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( isset( $result['error'] ) && $result['error'] ) {
			$message = is_string( $result['error'] ) ? $result['error'] : __( 'Failed to fetch subscriptions.', 'onecom-wp' );
			return new \WP_Error( 'subscription_error', $message, $result );
		}

		$list = $result['data']['subscriptions'] ?? null;

		if ( ! empty( $list ) && is_array( $list ) ) {
			set_site_transient( $transient_name, $list, 15 * MINUTE_IN_SECONDS );
		} else {
			$list = [];
		}

		return $list;
	}

	public function get_subscriptions_list(): void
	{
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error([ 'message' => 'Permission denied' ]);
		}

		// onecom uses a separate per-plugin purchase check registered by the host
		// plugin (wp_ajax_get_addon_purchase_status). Skip the marketplace-API list
		// and the transient cache entirely for that brand.
		if ( 'onecom' === ( $this->config['brand'] ?? '' ) ) {
			wp_send_json_success( [] );
		}

		$list = $this->fetch_subscriptions();

		if ( is_wp_error( $list ) ) {
			// Preserve the legacy shape: API-error responses forwarded the full result.
			$data = $list->get_error_data();
			wp_send_json_error( is_array( $data ) ? $data : [ 'message' => $list->get_error_message() ] );
		}

		// Stage license data carried on active subscriptions so it's written on activation.
		if ( ! empty( $list ) && is_array( $list ) ) {
			$this->stage_licenses_from_subscriptions( $list );
		}

		wp_send_json_success( $list );
	}

	public function cancel_subscriptions() {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		wp_send_json_success( [ 'message' => 'Subscriptions cancelled successfully.' ] );
	}

	/**
	 * Proxy a cancellation (unsubscribe) request to the external marketplace API.
	 * Sends a DELETE request with action wp-marketplace-unsubscribe.
	 * Clears the cached subscription list so the next fetch reflects the cancellation.
	 */
	public function ajax_unsubscribe(): void {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		$subscription_id = sanitize_text_field( $_POST['subscriptionId'] ?? '' );

		if ( empty( $subscription_id ) ) {
			wp_send_json_error( [ 'message' => 'Missing subscriptionId.' ] );
		}

		$payload = array_merge(
			$this->config['payload'] ?? [],
			[
				'action' => 'wp-marketplace-unsubscribe',
				'data'   => wp_json_encode( [ 'subscriptionID' => $subscription_id ] ),
			]
		);

		// onecom's partner API doesn't expect an 'action' key — strip it for that brand.
		if ( 'onecom' === ( $this->config['brand'] ?? '' ) ) {
			unset( $payload['action'] );
		}

		$result = $this->get_model()->request( $payload, 'DELETE' );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( [ 'message' => $result->get_error_message() ] );
		}

		if ( isset( $result['error'] ) && $result['error'] ) {
			wp_send_json_error( $result );
		}

		// Clear cached subscription list so next fetch returns fresh data
		$brand_name = $this->config['brand'];
		delete_site_transient( "{$brand_name}_subscription_list" );

		wp_send_json_success( $result );
	}

	/**
	 * Delete the cached subscription list transient so the next fetch returns fresh data from the API.
	 * Called by the frontend after a new subscription is initiated or completed.
	 */
	public function ajax_clear_subscription_list(): void {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		$brand_name = $this->config['brand'];
		delete_site_transient( "{$brand_name}_subscription_list" );

		wp_send_json_success( [ 'message' => 'Subscription list cache cleared.' ] );
	}

	/**
	 * Persist a pending cancellation to the DB so the cancelling state survives page reloads.
	 */
	public function ajax_save_pending_cancellation(): void {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		$slug            = sanitize_text_field( $_POST['slug'] ?? '' );
		$subscription_id = sanitize_text_field( $_POST['subscriptionId'] ?? '' );

		if ( empty( $slug ) || empty( $subscription_id ) ) {
			wp_send_json_error( [ 'message' => 'Missing required fields.' ] );
		}

		$brand_name       = $this->config['brand'];
		$option_name      = "{$brand_name}_marketplace_pending_cancellations";
		$pending          = get_option( $option_name, [] );
		$pending[ $slug ] = [
			'subscriptionId' => $subscription_id,
			'timestamp'      => time(),
		];
		update_option( $option_name, $pending, false );

		wp_send_json_success( [ 'message' => 'Pending cancellation saved.' ] );
	}

	/**
	 * Remove a pending cancellation entry from the DB once cancellation is confirmed.
	 */
	public function ajax_clear_pending_cancellation(): void {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		$slug = sanitize_text_field( $_POST['slug'] ?? '' );

		if ( empty( $slug ) ) {
			wp_send_json_error( [ 'message' => 'Missing slug.' ] );
		}

		$brand_name  = $this->config['brand'];
		$option_name = "{$brand_name}_marketplace_pending_cancellations";
		$pending     = get_option( $option_name, [] );
		if ( isset( $pending[ $slug ] ) ) {
			unset( $pending[ $slug ] );
			update_option( $option_name, $pending, false );
		}

		wp_send_json_success( [ 'message' => 'Pending cancellation cleared.' ] );
	}

	/**
	 * Return the list of banner slugs the current user has dismissed.
	 * Stored in WP user meta under `{brand}_marketplace_dismissed_banners`.
	 * Called from build_marketplace_config() so the data is available in the
	 * initial page load without an extra round-trip from React.
	 *
	 * @return string[]
	 */
	protected function get_dismissed_banners(): array {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return [];
		}
		$brand    = $this->config['brand'] ?: 'marketplace';
		$meta_key = "{$brand}_marketplace_dismissed_banners";
		$dismissed = get_user_meta( $user_id, $meta_key, true );
		return is_array( $dismissed ) ? $dismissed : [];
	}

	/**
	 * Persist a dismissed banner slug to WP user meta so it is never re-shown
	 * to the same user, even across different browsers or devices.
	 *
	 * Meta key: `{brand}_marketplace_dismissed_banners` (array of banner slugs)
	 */
	public function ajax_dismiss_banner(): void {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		$banner_slug = sanitize_text_field( wp_unslash( $_POST['banner_slug'] ?? '' ) );

		if ( empty( $banner_slug ) ) {
			wp_send_json_error( [ 'message' => 'Missing banner_slug.' ] );
			return;
		}

		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			wp_send_json_error( [ 'message' => 'Not logged in.' ] );
			return;
		}

		$brand     = $this->config['brand'] ?: 'marketplace';
		$meta_key  = "{$brand}_marketplace_dismissed_banners";
		$dismissed = get_user_meta( $user_id, $meta_key, true );
		$dismissed = is_array( $dismissed ) ? $dismissed : [];

		if ( ! in_array( $banner_slug, $dismissed, true ) ) {
			$dismissed[] = $banner_slug;
			update_user_meta( $user_id, $meta_key, $dismissed );
		}

		wp_send_json_success( [ 'message' => 'Banner dismissed.' ] );
	}

	/**
	 * Clear a pending procurement entry for a plugin.
	 * Called when procurement completes and plugin is installed, or on manual cleanup.
	 */
	public function ajax_clear_pending_procurement() {
		check_ajax_referer( 'marketplace_nonce', 'nonce' );

		$slug = sanitize_text_field( $_POST['slug'] ?? '' );

		if ( empty( $slug ) ) {
			wp_send_json_error( [ 'message' => 'Missing slug.' ] );
		}

		$brand_name  = $this->config['brand'];
		$option_name = "{$brand_name}_marketplace_pending_procurements";
		$pending     = get_option( $option_name, [] );

		if ( isset( $pending[ $slug ] ) ) {
			unset( $pending[ $slug ] );
			update_option( $option_name, $pending, false );
		}

		wp_send_json_success( [ 'message' => 'Pending procurement cleared.' ] );
	}
}
