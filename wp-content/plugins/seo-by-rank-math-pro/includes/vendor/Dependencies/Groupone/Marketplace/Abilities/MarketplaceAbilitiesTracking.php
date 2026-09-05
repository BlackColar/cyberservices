<?php
namespace RankMathPro\Dependencies\Groupone\Marketplace\Abilities;

use RankMathPro\Dependencies\Groupone\Marketplace\Utils\MixpanelClient;

/**
 * MCP ability-execution telemetry for Marketplace.
 *
 * Emits one analytics "event" hit per ability run that originates over MCP,
 * using an independent Mixpanel client.
 *
 * Property names deliberately mirror the frontend tracker
 * (`frontend/src/utils/mixpanelTracking.js`) so UI-driven and MCP-driven
 * marketplace actions can be compared in the same Mixpanel report. The two
 * sources are told apart by `item_source` (`MCP` here, `UI` in the frontend).
 */
class MarketplaceAbilitiesTracking {

	/** Constant item_source for every MCP ability event. */
	const ITEM_SOURCE = 'MCP';

	/**
	 * Placeholder item_category meaning "resolve the product's real category
	 * from the catalog at track time" — see describe().
	 */
	const CATEGORY_FROM_CATALOG = '@product';

	/** @var array<int,array<string,mixed>> Queued events, flushed on shutdown. */
	private static $queue = [];

	/** @var bool Whether the shutdown flush has been registered. */
	private static $flush_registered = false;

	/** @var array Marketplace configuration. */
	private static $config = [];

	/** @var array<string,array> Per-request catalog lookups, keyed by slug. */
	private static $catalog_cache = [];

	/**
	 * Hook the tracker. Called during Marketplace boot.
	 *
	 * No-op when the host has not recorded data consent, mirroring the
	 * frontend tracker which refuses to initialise Mixpanel without it.
	 *
	 * @param array $config Marketplace configuration.
	 */
	public static function boot( array $config = [] ): void {
		self::$config = $config;

		if ( ! self::has_consent() ) {
			return;
		}

		add_action( 'wp_after_execute_ability', [ self::class, 'record' ], 10, 3 );
	}

	/**
	 * Has the host recorded data consent for this site?
	 */
	public static function has_consent(): bool {
		return ! empty( self::$config['data_consent_status'] );
	}

	/**
	 * Ability slug prefix/slug => [ event_action, item_category ].
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function events(): array {
		return [
			'marketplace/install-plugin'    => [ 'plugin_installed', self::CATEGORY_FROM_CATALOG ],
			'marketplace/activate-plugin'   => [ 'plugin_activated', self::CATEGORY_FROM_CATALOG ],
			'marketplace/deactivate-plugin' => [ 'plugin_deactivated', self::CATEGORY_FROM_CATALOG ],
			'marketplace/delete-plugin'     => [ 'plugin_deleted', self::CATEGORY_FROM_CATALOG ],
			'list-products'                 => [ 'products_listed', 'marketplace' ],
			'get-product'                   => [ 'product_fetched', self::CATEGORY_FROM_CATALOG ],
			'list-installed'                => [ 'installed_plugins_listed', 'marketplace' ],
			'refresh-products'              => [ 'products_refreshed', 'marketplace' ],
			'list-subscriptions'            => [ 'subscriptions_listed', 'marketplace' ],
			'get-subscription'              => [ 'subscription_fetched', 'marketplace' ],
		];
	}

	/**
	 * `wp_after_execute_ability` listener. Queues a tracked ability run.
	 *
	 * @param string $name   Ability slug.
	 * @param mixed  $input  Input the ability was called with.
	 * @param mixed  $result Ability return value.
	 */
	public static function record( $name, $input, $result ): void {
		if ( ! self::is_mcp_request() ) {
			return;
		}

		$event = self::describe( $name, is_array( $input ) ? $input : [], $result );
		if ( null === $event ) {
			return;
		}

		self::$queue[] = $event;
		self::ensure_flush();
	}

	/**
	 * Build the tracking payload for one ability run.
	 *
	 * @param string $name   Ability slug.
	 * @param array  $input  Ability input.
	 * @param mixed  $result Ability return value.
	 * @return array<string,mixed>|null
	 */
	public static function describe( string $name, array $input, $result ): ?array {
		$events       = self::events();
		$event_config = null;

		if ( isset( $events[ $name ] ) ) {
			$event_config = $events[ $name ];
		} else {
			// Check for brand-prefixed slugs: {brand}-marketplace/list-products
			foreach ( $events as $suffix => $config ) {
				if ( strpos( $name, "-marketplace/{$suffix}" ) !== false ) {
					$event_config = $config;
					break;
				}
			}
		}

		if ( null === $event_config ) {
			return null;
		}

		$result_status = self::status_of( $result );
		$slug          = self::item_name( $name, $input, $result );

		// Product-scoped abilities report the product's real catalog category, so
		// the value matches what the UI sends for the same action.
		$item_category = self::CATEGORY_FROM_CATALOG === $event_config[1]
			? self::category_of( $slug )
			: $event_config[1];

		// Key names mirror the frontend `Button Clicked` / `Page Viewed` payloads.
		$props = [
			'event_action'  => $event_config[0],
			'item_category' => $item_category,
			'item_name'     => $slug,
			'product_slug'  => $slug,
			'product_name'  => self::product_name( $result, $slug ),
			'result'        => $result_status,
			'timestamp'     => (int) round( microtime( true ) * 1000 ),
		];

		if ( 'error' === $result_status ) {
			$props['error_code']    = self::error_code_of( $result );
			$props['error_message'] = self::error_message_of( $result );
		}

		return $props;
	}

	/**
	 * Is the current request an MCP call?
	 *
	 * A union of signals, because no single one is universally present:
	 *
	 * - `Mcp-Session-Id` / `MCP-Protocol-Version` headers — authoritative when
	 *   sent, but both optional. The session header exists only if the server
	 *   issued one at `initialize`, and the protocol header only entered the spec
	 *   in the 2025-06-18 revision. ChatGPT (`openai-mcp/1.0.0`) sends neither;
	 *   it carries its own `X-OpenAI-Session` instead.
	 * - `Accept: text/event-stream` — the streamable-HTTP transport requires
	 *   clients to accept both `application/json` and `text/event-stream` on
	 *   POST, so it is present exactly where the headers above are not.
	 * - A request path that resolves to an MCP endpoint (`…/<ns>/mcp`).
	 *
	 * Only ever consulted from `wp_after_execute_ability`, so the request is
	 * already known to be an ability execution. The non-MCP callers there are our
	 * own admin-ajax UI and the plain REST abilities route, neither of which sends
	 * `text/event-stream` nor posts to an `/mcp` path — so widening detection this
	 * way does not misattribute UI traffic.
	 */
	public static function is_mcp_request(): bool {
		$is_mcp = self::has_mcp_transport_headers()
			|| self::accepts_event_stream()
			|| self::is_mcp_endpoint_path();

		return (bool) apply_filters( 'onecom_abilities_is_mcp_request', $is_mcp );
	}

	/**
	 * The two MCP transport headers, when the client bothers to send them.
	 */
	private static function has_mcp_transport_headers(): bool {
		return ! empty( $_SERVER['HTTP_MCP_SESSION_ID'] ) || ! empty( $_SERVER['HTTP_MCP_PROTOCOL_VERSION'] );
	}

	/**
	 * Does the client accept an SSE stream? Mandatory for streamable-HTTP MCP POSTs.
	 */
	private static function accepts_event_stream(): bool {
		if ( empty( $_SERVER['HTTP_ACCEPT'] ) ) {
			return false;
		}

		$accept = strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) );

		return false !== strpos( $accept, 'text/event-stream' );
	}

	/**
	 * Does the request target an MCP endpoint?
	 *
	 * Covers pretty REST permalinks (`/wp-json/<ns>/mcp`), the `?rest_route=`
	 * fallback, and `X-Original-URL` for proxies that rewrite `REQUEST_URI`
	 * (group.one's Varnish tier does). Worst case a spoofed header mislabels one
	 * telemetry event, which grants a caller nothing.
	 */
	private static function is_mcp_endpoint_path(): bool {
		foreach ( [ 'REQUEST_URI', 'HTTP_X_ORIGINAL_URL' ] as $key ) {
			if ( empty( $_SERVER[ $key ] ) ) {
				continue;
			}

			$raw   = sanitize_text_field( wp_unslash( $_SERVER[ $key ] ) );
			$query = (string) wp_parse_url( $raw, PHP_URL_QUERY );
			$path  = (string) wp_parse_url( $raw, PHP_URL_PATH );

			// `?rest_route=/easy-mcp-ai/v1/mcp` when pretty permalinks are off.
			if ( '' !== $query ) {
				$args = [];
				wp_parse_str( $query, $args );
				if ( ! empty( $args['rest_route'] ) ) {
					$path = (string) $args['rest_route'];
				}
			}

			if ( '' === $path ) {
				continue;
			}

			if ( 'mcp' === strtolower( basename( untrailingslashit( $path ) ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Map an ability return value to success|error.
	 *
	 * Note: `wp_after_execute_ability` only fires on the success path — the
	 * Abilities API returns early on permission failure, input/output
	 * validation failure and on a `WP_Error` from the execute callback. Only
	 * "soft" failures (`[ 'success' => false ]`) reach us; the `WP_Error`
	 * branch below is defensive.
	 */
	private static function status_of( $result ): string {
		if ( is_wp_error( $result ) ) {
			return 'error';
		}
		if ( is_array( $result ) && array_key_exists( 'success', $result ) ) {
			return $result['success'] ? 'success' : 'error';
		}
		return 'success';
	}

	/**
	 * Extract a machine-readable error code from a failed result.
	 */
	private static function error_code_of( $result ): string {
		if ( is_wp_error( $result ) ) {
			return (string) $result->get_error_code();
		}
		if ( is_array( $result ) && ! empty( $result['code'] ) ) {
			return (string) $result['code'];
		}
		return '';
	}

	/**
	 * Extract the human-readable error message, matching the UI's
	 * `error_message` property on failed plugin actions.
	 */
	private static function error_message_of( $result ): string {
		if ( is_wp_error( $result ) ) {
			return (string) $result->get_error_message();
		}
		if ( is_array( $result ) && ! empty( $result['message'] ) ) {
			return (string) $result['message'];
		}
		return '';
	}

	/**
	 * Product display name, from the ability result or the cached catalog.
	 */
	private static function product_name( $result, string $slug ): string {
		if ( is_array( $result ) && ! empty( $result['name'] ) && is_string( $result['name'] ) ) {
			return $result['name'];
		}

		$item = self::catalog_item( $slug );
		return isset( $item['name'] ) && is_string( $item['name'] ) ? $item['name'] : '';
	}

	/**
	 * First catalog category for a product, resolved the same way the frontend
	 * does it (`categories[0].slug || categories[0].title`) so `item_category`
	 * carries identical values whether the action came from the UI or MCP.
	 */
	private static function category_of( string $slug ): string {
		$item = self::catalog_item( $slug );
		if ( empty( $item['categories'] ) || ! is_array( $item['categories'] ) ) {
			return '';
		}

		$first = reset( $item['categories'] );
		if ( ! is_array( $first ) ) {
			return (string) $first;
		}

		$value = $first['slug'] ?? $first['title'] ?? $first['name'] ?? '';
		return is_string( $value ) ? $value : '';
	}

	/**
	 * Look up one catalog item by slug.
	 *
	 * Reads the transient the controller primes rather than calling the catalog
	 * provider — telemetry must never turn a cold cache into a blocking upstream
	 * fetch on the caller's request. A cache miss just means the category and
	 * name are omitted from the event.
	 *
	 * @param string $slug Product slug.
	 * @return array Catalog item, or [] when unavailable.
	 */
	private static function catalog_item( string $slug ): array {
		if ( '' === $slug ) {
			return [];
		}

		if ( array_key_exists( $slug, self::$catalog_cache ) ) {
			return self::$catalog_cache[ $slug ];
		}

		self::$catalog_cache[ $slug ] = [];

		$brand = self::$config['brand'] ?? '';
		if ( '' === $brand || ! function_exists( 'get_site_transient' ) ) {
			return [];
		}

		$payload = get_site_transient( "{$brand}_marketplace_catalog" );
		if ( ! is_array( $payload ) ) {
			return [];
		}

		foreach ( self::catalog_items( $payload ) as $item ) {
			if ( is_array( $item ) && isset( $item['slug'] ) && (string) $item['slug'] === $slug ) {
				self::$catalog_cache[ $slug ] = $item;
				break;
			}
		}

		return self::$catalog_cache[ $slug ];
	}

	/**
	 * Flatten a catalog payload into a list of items. Mirrors
	 * MarketplaceAbilities::extract_items() — the upstream API has shipped
	 * several response shapes and all of them are still in the wild.
	 *
	 * @param array $payload Raw catalog payload.
	 * @return array
	 */
	private static function catalog_items( array $payload ): array {
		if ( ! empty( $payload['data']['catalog'] ) && is_array( $payload['data']['catalog'] ) ) {
			return $payload['data']['catalog'];
		}
		if ( isset( $payload['data'] ) && is_array( $payload['data'] ) && array_values( $payload['data'] ) === $payload['data'] ) {
			return $payload['data'];
		}
		if ( ! empty( $payload['data']['ui_json'] ) && is_array( $payload['data']['ui_json'] ) ) {
			return $payload['data']['ui_json'];
		}

		$items    = [];
		$sections = $payload['data']['sections'] ?? $payload['sections'] ?? [];
		if ( is_array( $sections ) ) {
			foreach ( $sections as $section ) {
				if ( ! empty( $section['items'] ) && is_array( $section['items'] ) ) {
					$items = array_merge( $items, $section['items'] );
				}
			}
		}
		return $items;
	}

	/**
	 * Derive item_name (the target of the operation).
	 */
	private static function item_name( string $name, array $input, $result ): string {
		// Slugs: marketplace/install-plugin, {brand}-marketplace/list-products, etc.
		if ( strpos( $name, 'install-plugin' ) !== false || strpos( $name, 'activate-plugin' ) !== false ||
		     strpos( $name, 'deactivate-plugin' ) !== false || strpos( $name, 'delete-plugin' ) !== false ||
		     strpos( $name, 'get-product' ) !== false ) {
			return isset( $input['slug'] ) ? sanitize_key( (string) $input['slug'] ) : '';
		}

		if ( strpos( $name, 'get-subscription' ) !== false ) {
			if ( ! empty( $input['subscriptionId'] ) ) {
				return sanitize_key( (string) $input['subscriptionId'] );
			}
			return isset( $input['slug'] ) ? sanitize_key( (string) $input['slug'] ) : '';
		}

		return '';
	}

	/**
	 * Register the one-shot shutdown flush.
	 */
	private static function ensure_flush(): void {
		if ( self::$flush_registered ) {
			return;
		}
		self::$flush_registered = true;
		add_action( 'shutdown', [ self::class, 'flush' ], 100 );
	}

	/**
	 * Send all queued events in a single batched request.
	 */
	public static function flush(): void {
		if ( empty( self::$queue ) ) {
			return;
		}

		$queue       = self::$queue;
		self::$queue = [];

		$token = self::get_mixpanel_token();
		if ( empty( $token ) ) {
			return;
		}

		$client_file = __DIR__ . '/../Utils/MixpanelClient.php';
		if ( ! class_exists( MixpanelClient::class, false ) && is_readable( $client_file ) ) {
			require_once $client_file;
		}

		if ( ! class_exists( MixpanelClient::class, false ) ) {
			return;
		}

		$common = self::get_common_properties();
		$events = [];

		foreach ( $queue as $event ) {
			$props = array_merge( $common, $event, [ 'item_source' => self::ITEM_SOURCE ] );

			$events[] = [
				'event'      => 'MCP Ability Executed',
				'properties' => self::without_empty( $props ),
			];
		}

		( new MixpanelClient( $token ) )->track_batch( $events );
	}

	/**
	 * Drop empty properties so the payload matches what the frontend sends
	 * (`getGlobalProperties()` filters empty strings, nulls and empty arrays).
	 *
	 * @param array $props Properties.
	 * @return array
	 */
	private static function without_empty( array $props ): array {
		return array_filter(
			$props,
			static function ( $value ) {
				if ( null === $value || '' === $value ) {
					return false;
				}
				return ! ( is_array( $value ) && empty( $value ) );
			}
		);
	}

	/**
	 * Get the Mixpanel token.
	 *
	 * The host passes the resolved token through config; the sandbox/production
	 * literals below are a fallback for hosts that boot Marketplace without one
	 * and must stay in step with MarketplaceController::get_mixpanel_token().
	 */
	private static function get_mixpanel_token(): string {
		if ( ! empty( self::$config['mixpanel']['token'] ) ) {
			return (string) self::$config['mixpanel']['token'];
		}

		$is_sandbox = ! empty( self::$config['mixp_props']['is_sandbox'] ) && self::$config['mixp_props']['is_sandbox'] === true;
		if ( $is_sandbox ) {
			return '4cdc36e9083c158244c3e26d280540f6';
		}

		return '517e881edc2636e99a2ecf013d8134d3';
	}

	/**
	 * Common Mixpanel properties for all events.
	 *
	 * Mirrors the global properties the frontend receives from
	 * MarketplaceController::build_marketplace_config(). Browser-only globals
	 * (`page`, `path`, `referrer`) have no MCP equivalent and are omitted.
	 */
	private static function get_common_properties(): array {
		$current_user = wp_get_current_user();

		$props = [
			'application' => 'wordpress_marketplace',
			'context'     => 'wp_plugin_mcp',
			'hit_type'    => 'event',
			'wp_version'  => get_bloginfo( 'version' ),
			'php_version' => phpversion(),
			'wp_locale'   => get_locale(),
			'locale'      => get_locale(),
		];

		if ( ! empty( self::$config['brand'] ) ) {
			$props['brand'] = self::$config['brand'];
		}

		if ( ! empty( self::$config['mixp_distinct_id'] ) ) {
			$props['distinct_id'] = self::$config['mixp_distinct_id'];
		}

		// Hashed exactly as the frontend globals are, so the values join up.
		if ( $current_user->exists() ) {
			$props['wp_user']        = $current_user->user_login ? hash( 'sha256', $current_user->user_login ) : '';
			$props['wp_admin_email'] = $current_user->user_email ? hash( 'sha256', $current_user->user_email ) : '';
			$props['wp_role']        = ! empty( $current_user->roles ) ? $current_user->roles[0] : '';
			$props['user_id']        = $current_user->ID;
		}

		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$props['user_agent'] = sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) );
		}

		// Only pick a few relevant properties from host-provided props to avoid
		// dependency on full ocpushstats payload. `is_sandbox` is deliberately
		// excluded — the frontend strips it before Mixpanel sees it.
		if ( ! empty( self::$config['mixp_props'] ) && is_array( self::$config['mixp_props'] ) ) {
			$relevant_keys = [ 'env', 'domain', 'user_id', 'hosting_package' ];
			foreach ( $relevant_keys as $key ) {
				if ( isset( self::$config['mixp_props'][ $key ] ) ) {
					$props[ $key ] = self::$config['mixp_props'][ $key ];
				}
			}
		}

		return $props;
	}
}
