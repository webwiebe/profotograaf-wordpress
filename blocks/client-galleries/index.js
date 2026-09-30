import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import './style.css';
import metadata from './block.json';

function Edit( { attributes, setAttributes } ) {
	const { portal, heading, description, buttonLabel, openInNewTab } =
		attributes;
	const blockProps = useBlockProps();

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Client portal', 'profotograaf' ) }>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Portal address', 'profotograaf' ) }
						help={ __(
							'Your Profotograaf address (for example studio), your subdomain or your own domain.',
							'profotograaf'
						) }
						value={ portal }
						onChange={ ( value ) =>
							setAttributes( { portal: value } )
						}
					/>
					<ToggleControl
						__nextHasNoMarginBottom
						label={ __( 'Open in a new tab', 'profotograaf' ) }
						checked={ openInNewTab }
						onChange={ ( value ) =>
							setAttributes( { openInNewTab: value } )
						}
					/>
				</PanelBody>
				<PanelBody
					title={ __( 'Text', 'profotograaf' ) }
					initialOpen={ false }
				>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Heading', 'profotograaf' ) }
						value={ heading }
						placeholder={ __(
							'Find your gallery',
							'profotograaf'
						) }
						onChange={ ( value ) =>
							setAttributes( { heading: value } )
						}
					/>
					<TextareaControl
						__nextHasNoMarginBottom
						label={ __( 'Text', 'profotograaf' ) }
						value={ description }
						placeholder={ __(
							'Sign in to the client portal to see your photos.',
							'profotograaf'
						) }
						onChange={ ( value ) =>
							setAttributes( { description: value } )
						}
					/>
					<TextControl
						__nextHasNoMarginBottom
						__next40pxDefaultSize
						label={ __( 'Button label', 'profotograaf' ) }
						value={ buttonLabel }
						placeholder={ __( 'Open my gallery', 'profotograaf' ) }
						onChange={ ( value ) =>
							setAttributes( { buttonLabel: value } )
						}
					/>
				</PanelBody>
			</InspectorControls>
			<div { ...blockProps }>
				<h3 className="wp-block-profotograaf-client-galleries__heading">
					{ heading || __( 'Find your gallery', 'profotograaf' ) }
				</h3>
				<p className="wp-block-profotograaf-client-galleries__text">
					{ description ||
						__(
							'Sign in to the client portal to see your photos.',
							'profotograaf'
						) }
				</p>
				<span className="wp-block-profotograaf-client-galleries__button">
					{ buttonLabel || __( 'Open my gallery', 'profotograaf' ) }
				</span>
				{ ! portal && (
					<p className="wp-block-profotograaf-client-galleries__notice">
						{ __(
							'Only editors see this note. Enter your Profotograaf address in the block settings. Until then visitors do not see this block.',
							'profotograaf'
						) }
					</p>
				) }
			</div>
		</>
	);
}

registerBlockType( metadata.name, {
	edit: Edit,
	save: () => null,
} );
