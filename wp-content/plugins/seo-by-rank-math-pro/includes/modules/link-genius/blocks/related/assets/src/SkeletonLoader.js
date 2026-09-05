/**
 * Skeleton loader component for Related Posts block.
 *
 * Shows a placeholder UI while the ServerSideRender is loading.
 */

export default () => {
	const SkeletonItem = () => (
		<div className="rank-math-related-skeleton-item">
			{ /* Thumbnail skeleton */ }
			<div className="rank-math-related-skeleton-thumb"></div>

			{ /* Content skeleton */ }
			<div className="rank-math-related-skeleton-content">
				{ /* Title skeleton */ }
				<div className="rank-math-related-skeleton-title"></div>
				<div className="rank-math-related-skeleton-title-line"></div>

				{ /* Excerpt skeleton */ }
				<div className="rank-math-related-skeleton-excerpt">
					<div className="rank-math-related-skeleton-line"></div>
					<div className="rank-math-related-skeleton-line"></div>
					<div className="rank-math-related-skeleton-line short"></div>
				</div>
			</div>
		</div>
	)

	return (
		<div className="rank-math-related-skeleton">
			{ Array.from( { length: 3 } ).map( ( _, i ) => (
				<SkeletonItem key={ i } />
			) ) }
		</div>
	)
}
