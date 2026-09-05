/**
 * WordPress dependencies
 */
import { __ } from '@wordpress/i18n'
import { InspectorControls, BlockControls, useBlockProps } from '@wordpress/block-editor'
import { useState, useEffect, useCallback } from '@wordpress/element'
import ServerSideRender from '@wordpress/server-side-render'
import { PanelBody, RangeControl, ToggleControl, SelectControl, TextControl, ToolbarButton, Notice } from '@wordpress/components'

/**
 * Internal dependencies
 */
import blockJson from './block.json'
import buildCurrentSummary from '../../../../assets/src/helpers/buildCurrentSummary'
import { fetchRelatedPosts } from '../../../../assets/src/services/api/linkGeniusApi'
import ContentAIErrorHandler, { hasContentAIError } from '../../../../assets/src/components/common/ContentAIErrorHandler'
import './styles.scss'
import SkeletonLoader from './SkeletonLoader'

// Check if Content AI module is active
const isContentAIModuleActive = () => {
	return window?.rankMath?.modules && Object.values( window.rankMath.modules ).includes( 'content-ai' )
}

const Edit = ( { attributes, setAttributes } ) => {
	const [ refreshing, setRefreshing ] = useState( false )
	const [ refreshTick, setRefreshTick ] = useState( 0 )
	const [ fetchError, setFetchError ] = useState( null )

	const doRefresh = useCallback( async ( { markInitialized = false } = {} ) => {
		if ( refreshing ) {
			return
		}
		setRefreshing( true )
		setFetchError( null )
		const data = { post_id: rankMath.objectID }
		try {
			// Always build and send current summary so backend can compute related posts.
			data.current = await buildCurrentSummary()
		} catch ( err ) {
			console.error( err )
		}

		fetchRelatedPosts( rankMath.objectID, data.current ).then( () => {
			// Remount SSR to fetch fresh HTML after backend refresh.
			setRefreshTick( ( t ) => t + 1 )
			setFetchError( null )
		} ).catch( ( err ) => {
			setFetchError( err )
		} ).finally( () => {
			if ( markInitialized ) {
				// Even if the request fails, stop blocking SSR after first attempt.
				setAttributes( { initialized: true } )
			}
			setRefreshing( false )
		} )
	}, [ refreshing, setAttributes ] )

	// On first insert (not yet initialized), check if related posts already exist
	// in postmeta (via localized data) to avoid unnecessary AI requests.
	useEffect( () => {
		if ( ! attributes.initialized ) {
			const existingItems = window?.rankMath?.linkGenius?.relatedItems
			if ( existingItems && existingItems.length > 0 ) {
				setAttributes( { initialized: true } )
			} else {
				doRefresh( { markInitialized: true } )
			}
		}
	}, [] )

	return (
		<>
			<BlockControls>
				<ToolbarButton
					icon="update"
					label={ __( 'Refresh related posts', 'rank-math' ) }
					onClick={ () => doRefresh() }
					disabled={ refreshing }
				/>
			</BlockControls>
			<InspectorControls>
				<PanelBody title={ __( 'UX', 'rank-math' ) } initialOpen={ true }>
					<RangeControl
						label={ __( 'Number of posts', 'rank-math' ) }
						value={ attributes.number }
						min={ 1 }
						max={ 10 }
						onChange={ ( value ) => setAttributes( { number: value } ) }
					/>
					<ToggleControl
						label={ __( 'Show image', 'rank-math' ) }
						checked={ attributes.showImage }
						onChange={ ( value ) => setAttributes( { showImage: value } ) }
					/>
					{
						attributes.showImage && <SelectControl
							label={ __( 'Image size', 'rank-math' ) }
							value={ attributes.imageSize }
							options={ [
								{ value: 'thumbnail', label: __( 'Thumbnail', 'rank-math' ) },
								{ value: 'medium', label: __( 'Medium', 'rank-math' ) },
								{ value: 'large', label: __( 'Large', 'rank-math' ) },
							] }
							onChange={ ( value ) => setAttributes( { imageSize: value } ) }
						/>
					}
					<ToggleControl
						label={ __( 'Show excerpt', 'rank-math' ) }
						checked={ attributes.showExcerpt }
						onChange={ ( value ) => setAttributes( { showExcerpt: value } ) }
					/>
					<ToggleControl
						label={ __( 'Show date', 'rank-math' ) }
						checked={ attributes.showDate }
						onChange={ ( value ) => setAttributes( { showDate: value } ) }
					/>
					<ToggleControl
						label={ __( 'Show taxonomy chips', 'rank-math' ) }
						checked={ attributes.showTerms }
						onChange={ ( value ) => setAttributes( { showTerms: value } ) }
					/>
				</PanelBody>
				<PanelBody title={ __( 'Texts', 'rank-math' ) } initialOpen={ false }>
					<TextControl
						label={ __( 'Title', 'rank-math' ) }
						value={ attributes.title }
						onChange={ ( value ) => setAttributes( { title: value } ) }
					/>
					<TextControl
						label={ __( 'Button text', 'rank-math' ) }
						value={ attributes.buttonText }
						onChange={ ( value ) => setAttributes( { buttonText: value } ) }
					/>
					<TextControl
						label={ __( 'Button URL', 'rank-math' ) }
						value={ attributes.buttonUrl }
						onChange={ ( value ) => setAttributes( { buttonUrl: value } ) }
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...useBlockProps() }>
				{ fetchError ? ( () => {
					const hasContentAIIssue = ! isContentAIModuleActive() || hasContentAIError()

					return (
						<div>
							{ /* Show ContentAIErrorHandler only for Content AI specific issues */ }
							{ hasContentAIIssue && (
								<ContentAIErrorHandler
									hasExistingData={ false }
									onModuleEnabled={ () => {
										// Retry after module is enabled
										setTimeout( () => doRefresh(), 500 )
									} }
								/>
							) }
							{ /* Show actual error message for AI service errors */ }
							{ ! hasContentAIIssue && (
								<Notice status="error" isDismissible={ false }>
									{ fetchError?.message || __( 'An error occurred while fetching related posts.', 'rank-math-pro' ) }
								</Notice>
							) }
						</div>
					)
				} )() : attributes.initialized && ! refreshing ? (
					<ServerSideRender
						key={ refreshTick }
						block={ blockJson.name }
						attributes={ attributes }
						ErrorResponsePlaceholder={ ( { error } ) => {
							const hasContentAIIssue = ! isContentAIModuleActive() || hasContentAIError()

							// Show ContentAIErrorHandler only for Content AI specific issues
							if ( hasContentAIIssue ) {
								return (
									<div>
										<ContentAIErrorHandler
											hasExistingData={ false }
											onModuleEnabled={ () => {
												// Retry after module is enabled
												setTimeout( () => doRefresh(), 500 )
											} }
										/>
									</div>
								)
							}
							// Show actual error message for AI service errors
							return (
								<Notice status="error" isDismissible={ false }>
									<strong>{ __( 'Failed to load related posts.', 'rank-math' ) }</strong>
									<p>{ error?.message || __( 'An unknown error occurred. Please try refreshing.', 'rank-math' ) }</p>
									<button
										className="button button-primary"
										onClick={ () => doRefresh() }
										disabled={ refreshing }
									>
										{ refreshing ? __( 'Refreshing…', 'rank-math' ) : __( 'Retry', 'rank-math' ) }
									</button>
								</Notice>
							)
						} }
					/>
				) : (
					<div>
						<SkeletonLoader />
					</div>
				) }
			</div>
		</>
	)
}

export default Edit
