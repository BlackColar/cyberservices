<?php
/**
 * The Video Schema.
 *
 * @since      1.0
 * @package    RankMath
 * @subpackage RankMath\Schema
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Schema;

use RankMath\Helper;
use RankMath\Traits\Hooker;

defined( 'ABSPATH' ) || exit;

/**
 * Video class.
 */
class Video {

	use Hooker;

	/**
	 * The Constructor.
	 */
	public function __construct() {
		Video_Schema_Generator::get();

		if ( Helper::is_rest() ) {
			$this->filter( 'rank_math/tools/generate_video_schema', 'generate_video_schema' );
		}

		$this->filter( 'rank_math/database/tools', 'generate_video_schema_tool' );
		$this->action( 'rank_math/pre_update_metadata', 'detect_video_in_content', 10, 3 );
		if ( is_admin() ) {
			return;
		}

		$this->action( 'rank_math/opengraph/facebook', 'add_video_tags', 99 );
		new Media_RSS();
	}

	/**
	 * Output the video tags.
	 *
	 * @link https://yandex.com/support/video/partners/open-graph.html#player
	 *
	 * @param OpenGraph $opengraph The current opengraph network object.
	 */
	public function add_video_tags( $opengraph ) {
		if ( ! is_singular() ) {
			return;
		}

		global $post;
		$video_data = get_post_meta( $post->ID, 'rank_math_schema_VideoObject', true );
		if ( empty( $video_data ) ) {
			return;
		}

		$tags = [
			'ya:ovs:adult'       => ! empty( $video_data['isFamilyFriendly'] ) ? false : true,
			'ya:ovs:upload_date' => ! empty( $video_data['uploadDate'] ) ? Helper::replace_vars( $video_data['uploadDate'], $post ) : '',
			'ya:ovs:allow_embed' => ! empty( $video_data['embedUrl'] ) ? 'true' : 'false',
		];

		foreach ( $tags as $tag => $value ) {
			$opengraph->tag( $tag, $value );
		}
	}

	/**
	 * Automatically add Video Schema when post is updated.
	 *
	 * @param int    $object_id   Object ID.
	 * @param int    $object_type Object type.
	 * @param string $content     Updated post content.
	 */
	public function detect_video_in_content( $object_id, $object_type, $content = '' ) {
		if ( 'post' !== $object_type ) {
			return;
		}

		$post = get_post( $object_id );
		if ( $content ) {
			$post->post_content = $content;
		}

		( new Video\Parser( $post ) )->save();
	}

	/**
	 * Add database tools.
	 *
	 * @param array $tools Array of tools.
	 *
	 * @return array
	 */
	public function generate_video_schema_tool( $tools ) {
		$posts = Video_Schema_Generator::get()->find_posts();
		if ( empty( $posts ) ) {
			return $tools;
		}

		$generate_video_schema = [
			'generate_video_schema' => [
				'title'        => esc_html__( 'Generate Video Schema for Old Posts/Pages', 'rank-math-pro' ),
				'description'  => esc_html__( 'Add Video schema to posts which have YouTube or Vimeo Video in the content. Applies to only those Posts/Pages/CPTs in which Autodetect Video Option is On.', 'rank-math-pro' ),
				'confirm_text' => esc_html__( 'Are you sure you want to add Video Schema to the posts/pages with the video in the content? This action is irreversible.', 'rank-math-pro' ),
				'button_text'  => esc_html__( 'Generate', 'rank-math-pro' ),
			],
		];

		$index = array_search( 'recreate_tables', array_keys( $tools ), true );
		$pos   = false === $index ? count( $tools ) : $index + 1;
		$tools = array_slice( $tools, 0, $pos, true ) + $generate_video_schema + array_slice( $tools, $pos, count( $tools ) - 1, true );

		return $tools;
	}

	/**
	 * Detect Video in the content and add schema.
	 *
	 * @return string
	 */
	public function generate_video_schema() {
		$posts = Video_Schema_Generator::get()->find_posts();
		if ( empty( $posts ) ) {
			return esc_html__( 'No posts found to convert.', 'rank-math-pro' );
		}

		Video_Schema_Generator::get()->start( $posts );

		return esc_html__( 'Conversion started. A success message will be shown here once the process completes. You can close this page.', 'rank-math-pro' );
	}
}
