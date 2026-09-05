<?php
/**
 * Pro SEO Reports in Email.
 *
 * @since      2.0.0
 * @package    RankMathPro
 * @subpackage RankMathPro\modules
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Analytics;

use RankMath\KB;
use RankMath\Helper;
use RankMath\Helpers\Param;
use RankMath\Traits\Hooker;
use RankMath\Analytics\Stats;
use RankMath\Admin\Admin_Helper;
use RankMath\Google\Authentication;
use RankMath\Analytics\Email_Reports as Email_Reports_Base;

use RankMathPro\Admin\Admin_Helper as ProAdminHelper;
use RankMathPro\Analytics\Keywords;
use RankMathPro\Analytics\Posts;

defined( 'ABSPATH' ) || exit;

/**
 * DB class.
 */
class Email_Reports {

	use Hooker;

	/**
	 * Path to the views folder.
	 *
	 * @var string
	 */
	public $views_path = '';

	/**
	 * URL of the module's assets folder.
	 *
	 * @var string
	 */
	public $assets_url = '';

	/**
	 * Get instance of the class.
	 */
	public static function get() {
		static $instance = null;

		if ( is_null( $instance ) ) {
			$instance = new self();
		}

		return $instance;
	}

	/**
	 * The constructor.
	 */
	public function __construct() {
		$this->hooks();
	}

	/**
	 * Add filter & action hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		$this->views_path = __DIR__ . '/views/email-reports/';
		$this->assets_url = plugin_dir_url( __FILE__ ) . 'assets/';

		// WP hooks.
		$this->filter( 'admin_post_rank_math_save_wizard', 'save_wizard' );

		// Rank Math hooks.
		$this->filter( 'rank_math/analytics/email_report_template_paths', 'add_template_path' );
		$this->filter( 'rank_math/analytics/email_report_variables', 'add_variables' );
		$this->filter( 'rank_math/analytics/email_report_parameters', 'email_parameters' );
		$this->filter( 'rank_math/analytics/email_report_image_atts', 'replace_logo', 10, 2 );
		$this->filter( 'rank_math/analytics/email_report_periods', 'frequency_periods' );
	}

	/**
	 * Replace logo image in template.
	 *
	 * @param array  $atts   All original attributes.
	 * @param string $url    Image URL or identifier.
	 *
	 * @return array
	 */
	public function replace_logo( $atts, $url ) {
		if ( 'report-logo.png' !== $url ) {
			return $atts;
		}

		$atts['src'] = '###LOGO_URL###';
		$atts['alt'] = '###LOGO_ALT###';

		return $atts;
	}

	/**
	 * Add Pro variables.
	 *
	 * @param array $variables Original variables.
	 * @return array
	 */
	public function add_variables( $variables ) {
		$variables['pro_assets_url'] = $this->assets_url;

		$variables['logo_url'] = Email_Reports_Base::get_setting( 'logo', $this->get_logo_url_default() );
		$variables['logo_alt'] = __( 'Logo', 'rank-math-pro' );

		$image_id = Email_Reports_Base::get_setting( 'logo_id', 0 );
		if ( $image_id ) {
			$alt = get_post_meta( $image_id, '_wp_attachment_image_alt', true );
			if ( $alt ) {
				$variables['logo_alt'] = $alt;
			}
		}

		$variables['header_background'] = Email_Reports_Base::get_setting( 'header_background', 'linear-gradient(90deg, rgba(112,83,181,1) 0%, rgba(73,153,210,1) 100%)' );
		$variables['top_html']          = wp_kses_post( wpautop( Email_Reports_Base::get_setting( 'top_text', '' ) ) );
		$variables['footer_html']       = wp_kses_post( Email_Reports_Base::get_setting( 'footer_text', $this->get_default_footer_text() ) );
		$variables['custom_css']        = Email_Reports_Base::get_setting( 'custom_css', '' );
		$variables['logo_link']         = Email_Reports_Base::get_setting( 'logo_link', KB::get( 'email-reports', 'PRO Email Report Logo' ) );

		// Get Pro stats.
		$period = Email_Reports_Base::get_period_from_frequency();
		Stats::get()->set_date_range( "-{$period} days" );

		$keywords = Keywords::get();
		if ( Email_Reports_Base::get_setting( 'tracked_keywords', false ) ) {
			$variables['winning_keywords'] = $keywords->get_tracked_winning_keywords();
			$variables['losing_keywords']  = $keywords->get_tracked_losing_keywords();
		} else {
			$variables['winning_keywords'] = $keywords->get_winning_keywords();
			$variables['losing_keywords']  = $keywords->get_losing_keywords();
		}

		$posts                      = Posts::get();
		$variables['winning_posts'] = $posts->get_winning_posts();
		$variables['losing_posts']  = $posts->get_losing_posts();

		return $variables;
	}


	/**
	 * Get default value for footer text option.
	 *
	 * @return string
	 */
	public function get_default_footer_text() {
		return join(
			' ',
			[
				// Translators: placeholder is a link to the homepage.
				sprintf( esc_html__( 'This email was sent to you as a registered member of %s.', 'rank-math-pro' ), '<a href="###SITE_URL###">###SITE_URL_SIMPLE###</a>' ),

				// Translators: placeholder is a link to the settings, with "click here" as the anchor text.
				sprintf( esc_html__( 'To update your email preferences, %s. ###ADDRESS###', 'rank-math-pro' ), '<a href="###SETTINGS_URL###">' . esc_html__( 'click here', 'rank-math-pro' ) . '</a>' ),
			]
		);
	}

	/**
	 * Change email parameters if needed.
	 *
	 * @param  array $email Parameters array.
	 * @return array
	 */
	public function email_parameters( $email ) {
		$email['to']      = Email_Reports_Base::get_setting( 'send_to', Admin_Helper::get_registration_data()['email'] );
		$email['subject'] = Email_Reports_Base::get_setting( 'subject', $this->get_subject_default() );

		return $email;
	}

	/**
	 * Get 'value' & 'diff' for the stat template part.
	 *
	 * @param mixed  $data Stats data.
	 * @param string $item Item we want to extract.
	 * @return array
	 */
	public static function get_stats_val( $data, $item ) {
		$value = isset( $data[ $item ]['total'] ) ? $data[ $item ]['total'] : 0;
		$diff  = isset( $data[ $item ]['difference'] ) ? $data[ $item ]['difference'] : 0;

		return compact( 'value', 'diff' );
	}

	/**
	 * Save additional wizard options.
	 *
	 * @return bool
	 */
	public function save_wizard() {
		$referer = Param::post( '_wp_http_referer' );
		if ( empty( $_POST ) ) {
			return wp_safe_redirect( $referer );
		}

		check_admin_referer( 'rank-math-wizard', 'security' );
		if ( ! Helper::has_cap( 'general' ) ) {
			return false;
		}

		$send_to = Param::post( 'console_email_send_to' );
		if ( ! $send_to ) {
			return true;
		}

		$settings = rank_math()->settings->all_raw();

		$settings['general']['console_email_send_to'] = $send_to;
		Helper::update_all_settings( $settings['general'], null, null );

		return true;
	}

	/**
	 * Add element and script for background preview.
	 *
	 * @return string
	 */
	public function get_bg_preview() {
		$script = '
			<script>
				jQuery( function() {
					jQuery( "#console_email_header_background" ).on( "change", function() {
						jQuery( ".rank-math-preview-bg" ).css( "background", jQuery( this ).val() );
					} );
				} );
			</script>
		';

		return '<div class="rank-math-preview-bg" data-title="' . esc_attr( __( 'Preview', 'rank-math-pro' ) ) . '" style="background: ' . esc_attr( Helper::get_settings( 'general.console_email_header_background', $this->get_header_bg_default() ) ) . '"></div>' . $script;
	}

	/**
	 * Get default value for the Header Background option.
	 *
	 * @return string
	 */
	public function get_header_bg_default() {
		return 'linear-gradient(90deg, #724BB7 0%, #4098D7 100%)';
	}

	/**
	 * Get default value for the Logo URL option.
	 *
	 * @return string
	 */
	public function get_logo_url_default() {
		$url = \rank_math()->plugin_url() . 'includes/modules/analytics/assets/img/';
		return $url . 'report-logo.png';
	}

	/**
	 * Get default value for the Subject option.
	 *
	 * @return string
	 */
	public function get_subject_default() {
		return sprintf(
			// Translators: placeholder is the site URL.
			__( 'Rank Math [SEO Report] - %s', 'rank-math-pro' ),
			explode( '://', get_home_url() )[1]
		);
	}

	/**
	 * Shorten a URL, like http://example-url...long-page/
	 *
	 * @param string  $url URL to shorten.
	 * @param integer $max Max length in characters.
	 * @return string
	 */
	public static function shorten_url( $url, $max = 16 ) {
		$length = strlen( $url );

		if ( $length <= $max + 3 ) {
			return $url;
		}

		return substr_replace( $url, '...', $max / 2, $length - $max );
	}

	/**
	 * Add pro template path to paths.
	 *
	 * @param string[] $paths Original paths.
	 * @return string[]
	 */
	public function add_template_path( $paths ) {
		$paths[] = $this->views_path;
		return $paths;
	}

	/**
	 * Add day numbers for new frequencies.
	 *
	 * @param array $periods Original periods.
	 * @return array
	 */
	public function frequency_periods( $periods ) {
		$periods['every_15_days'] = 15;
		$periods['weekly']        = 7;

		return $periods;
	}
}
