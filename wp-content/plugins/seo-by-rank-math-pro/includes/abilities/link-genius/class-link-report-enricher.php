<?php
/**
 * Enriches the FREE get-link-report result with PRO Link Genius audit data.
 *
 * @since      3.0.80
 * @package    RankMathPro
 * @subpackage RankMathPro\Link_Genius\Abilities
 * @author     Rank Math <support@rankmath.com>
 */

namespace RankMathPro\Link_Genius\Abilities;

use RankMathPro\Link_Genius\Data\Query_Builder;

defined( 'ABSPATH' ) || exit;

/**
 * Adds Link Genius audit data to the FREE link report via the
 * rank_math/abilities/link_report_result filter.
 */
class Link_Report_Enricher {

	/**
	 * Enrich the FREE link report result with PRO audit data.
	 *
	 * @param array $result Result array from the FREE runner.
	 * @return array
	 */
	public function enrich( array $result ): array {
		$stats = Query_Builder::get_audit_stats();

		$result['audit'] = [
			'has_run_before'           => Query_Builder::has_audit_run_before(),
			'total_links'              => (int) ( $stats['total'] ?? 0 ),
			'broken'                   => (int) ( $stats['status_4xx'] ?? 0 ) + (int) ( $stats['status_5xx'] ?? 0 ) + (int) ( $stats['timeout'] ?? 0 ) + (int) ( $stats['error'] ?? 0 ),
			'redirects'                => (int) ( $stats['status_3xx'] ?? 0 ),
			'nofollow'                 => (int) ( $stats['nofollow'] ?? 0 ),
			'http_status_distribution' => [
				'status_2xx' => (int) ( $stats['status_2xx'] ?? 0 ),
				'status_3xx' => (int) ( $stats['status_3xx'] ?? 0 ),
				'status_4xx' => (int) ( $stats['status_4xx'] ?? 0 ),
				'status_5xx' => (int) ( $stats['status_5xx'] ?? 0 ),
				'timeout'    => (int) ( $stats['timeout'] ?? 0 ),
				'error'      => (int) ( $stats['error'] ?? 0 ),
			],
		];

		unset( $result['upgrade'] );

		return $result;
	}
}
