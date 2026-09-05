<?php
namespace RankMathPro\Dependencies\Groupone\Marketplace;
use RankMathPro\Dependencies\Groupone\Marketplace\Controllers\MarketplaceController;

/**
 * Market Place Embeddable Module
 */


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Marketplace {
	/**
	 * Module version. `composer.json`'s `version` field is the single source of
	 * truth (tag-driven releases read it); this constant is bumped in the same
	 * release PR and the `package-validation` CI job fails if the two diverge.
	 * A compiled constant — not a runtime composer.json read — because under
	 * Mozart embedding composer.json is not guaranteed to ship beside the class.
	 */
	public const VERSION = '2.0.8';

	/**
	 * Boots the Marketplace with given config.
	 *
	 * MCP: the module registers its abilities (shared `marketplace/*` actions +
	 * per-brand `{brand}-marketplace/list-products` catalog) with meta.mcp.public = true
	 * during boot (see MarketplaceController::init -> MarketplaceAbilities::register).
	 * That is the entire integration — any host that runs an MCP default server
	 * exposes them automatically, with no host-side wiring. Nothing to inject here.
	 *
	 * @param array $config Configuration options for the marketplace module.
	 */
	private static $booted_locations = [];

	public static function run( array $config = [] ) {

        // Prevent duplicate Marketplace boot for same menu location
	    $parent = $config['parent_menu_slug'] ?? '';
        $slug   = $config['menu_slug']        ?? '';
        $key = $parent . '::' . $slug;

        if ( isset(self::$booted_locations[$key]) ) {
            return;
        }
        self::$booted_locations[$key] = true;

		try {
			MarketplaceController::boot($config);
		} catch (\Exception $e) {
			error_log($e->getMessage());
		}
	}
}
