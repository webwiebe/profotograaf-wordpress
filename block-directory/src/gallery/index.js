import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { Notice, PanelBody, Placeholder, SelectControl, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import metadata from './block.json';
import './editor.css';

const SIGNUP = 'https://profotograaf.nl/?utm_source=wordpress-block-directory';
const FULL_PLUGIN = 'https://wordpress.org/plugins/profotograaf/';

const LAYOUTS = [
	{ label: __( 'Automatic', 'profotograaf-gallery-block' ), value: 'auto' },
	{ label: __( 'Grid', 'profotograaf-gallery-block' ), value: 'grid' },
	{ label: __( 'Masonry', 'profotograaf-gallery-block' ), value: 'masonry' },
	{ label: __( 'Slideshow', 'profotograaf-gallery-block' ), value: 'slideshow' },
];

function Sidebar( { layout, setAttributes } ) {
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Gallery', 'profotograaf-gallery-block' ) }>
				<SelectControl
					label={ __( 'Layout', 'profotograaf-gallery-block' ) }
					value={ layout }
					options={ LAYOUTS }
					onChange={ ( value ) => setAttributes( { layout: value } ) }
					__next40pxDefaultSize
				/>
				<Notice status="info" isDismissible={ false }>
					{ __( 'The full Profotograaf plugin adds a gallery picker and lead capture.', 'profotograaf-gallery-block' ) }{ ' ' }
					<a href={ FULL_PLUGIN } target="_blank" rel="noreferrer noopener">
						{ __( 'See the plugin', 'profotograaf-gallery-block' ) }
					</a>
				</Notice>
			</PanelBody>
		</InspectorControls>
	);
}

function SignupLink() {
	return (
		<p>
			<a href={ SIGNUP } target="_blank" rel="noreferrer noopener">
				{ __( 'No gallery yet? Create a free Profotograaf account.', 'profotograaf-gallery-block' ) }
			</a>
		</p>
	);
}

function Edit( { attributes, setAttributes } ) {
	const { source, layout } = attributes;

	return (
		<div { ...useBlockProps() }>
			<Sidebar layout={ layout } setAttributes={ setAttributes } />
			<Placeholder
				icon="format-gallery"
				label={ __( 'Profotograaf client gallery', 'profotograaf-gallery-block' ) }
				instructions={ __( 'Paste the link of a gallery that allows embedding.', 'profotograaf-gallery-block' ) }
			>
				<TextControl
					label={ __( 'Gallery link', 'profotograaf-gallery-block' ) }
					type="url"
					value={ source }
					placeholder="https://profotograaf.nl/share/g/…"
					onChange={ ( value ) => setAttributes( { source: value } ) }
					__next40pxDefaultSize
				/>
				{ '' === source && <SignupLink /> }
			</Placeholder>
		</div>
	);
}

registerBlockType( metadata.name, { edit: Edit, save: () => null } );
