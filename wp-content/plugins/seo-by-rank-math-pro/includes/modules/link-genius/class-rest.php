<?php
/**
 * Link Genius REST endpoints facade.
 *
 * This class serves as a facade for backward compatibility, delegating
 * to domain-specific controllers in the Api/ directory.
 *
 * @package RankMath
 * @subpackage RankMathPro\Link_Genius
 */

namespace RankMathPro\Link_Genius;

use WP_REST_Controller;
use RankMath\Rest\Rest_Helper;
use RankMathPro\Link_Genius\Api\Links_Controller;
use RankMathPro\Link_Genius\Api\Editor_Controller;
use RankMathPro\Link_Genius\Api\Audit_Controller;
use RankMathPro\Link_Genius\Api\Export_Controller;
use RankMathPro\Link_Genius\Api\Posts_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * Rest class for Link Genius.
 *
 * This is a facade class that initializes and coordinates the domain-specific
 * REST controllers. It maintains backward compatibility by keeping the same
 * class name and public interface.
 */
class Rest extends WP_REST_Controller {

	/**
	 * Links controller instance.
	 *
	 * @var Links_Controller
	 */
	protected $links_controller;

	/**
	 * Editor controller instance.
	 *
	 * @var Editor_Controller
	 */
	protected $editor_controller;

	/**
	 * Audit controller instance.
	 *
	 * @var Audit_Controller
	 */
	protected $audit_controller;

	/**
	 * Export controller instance.
	 *
	 * @var Export_Controller
	 */
	protected $export_controller;

	/**
	 * Posts controller instance.
	 *
	 * @var Posts_Controller
	 */
	protected $posts_controller;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->namespace = Rest_Helper::BASE . '/link-genius';

		// Initialize domain-specific controllers.
		$this->links_controller  = new Links_Controller();
		$this->editor_controller = new Editor_Controller();
		$this->audit_controller  = new Audit_Controller();
		$this->export_controller = new Export_Controller();
		$this->posts_controller  = new Posts_Controller();
	}

	/**
	 * Registers the routes for all controllers.
	 */
	public function register_routes() {
		// Register routes from all domain controllers.
		$this->links_controller->register_routes();
		$this->editor_controller->register_routes();
		$this->audit_controller->register_routes();
		$this->export_controller->register_routes();
		$this->posts_controller->register_routes();
	}
}
