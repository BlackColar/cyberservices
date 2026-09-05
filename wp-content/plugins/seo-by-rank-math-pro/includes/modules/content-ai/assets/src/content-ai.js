/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n'
import { addFilter } from '@wordpress/hooks'

addFilter( 'rank_math_content_ai_credits_notice', 'rank-math-pro', () => {
	return __( 'You have reached the monthly limit for this feature. Please contact your SEO service provider to get more usage.', 'rank-math-pro' )
} )

addFilter( 'rank_math_content_ai_usage_features', 'rank-math-pro', ( features ) => {
	return [
		...features,
		{ key: 'suggest_link_opportunities', label: __( 'Link Opportunities', 'rank-math-pro' ) },
		{ key: 'related_posts', label: __( 'Related Posts', 'rank-math-pro' ) },
		{ key: 'suggest_links', label: __( 'Link Suggestions', 'rank-math-pro' ) },
	]
} )
