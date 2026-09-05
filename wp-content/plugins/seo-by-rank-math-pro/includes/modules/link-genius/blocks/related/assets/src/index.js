/**
 * WordPress dependencies
 */
import { registerBlockType } from '@wordpress/blocks'

/**
 * Internal dependencies
 */
import blockJson from './block.json'
import edit from './edit'

registerBlockType( blockJson.name, {
	...blockJson,
	edit,
} )
