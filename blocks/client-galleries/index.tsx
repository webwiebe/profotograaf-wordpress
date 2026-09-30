import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import './style.css';
import { registerServerRenderedBlock } from '../register';
import metadata from './block.json';
import { previewCopy } from './helpers';
import type { ClientGalleriesAttributes } from './helpers';

interface EditProps {
	attributes: ClientGalleriesAttributes;
	setAttributes: ( next: Partial< ClientGalleriesAttributes > ) => void;
}

function PortalPanel( { attributes, setAttributes }: EditProps ) {
	const { portal, openInNewTab } = attributes;
	return (
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
				onChange={ ( value ) => setAttributes( { portal: value } ) }
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
	);
}

function TextPanel( { attributes, setAttributes }: EditProps ) {
	const { heading, description, buttonLabel } = attributes;
	return (
		<PanelBody title={ __( 'Text', 'profotograaf' ) } initialOpen={ false }>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Heading', 'profotograaf' ) }
				value={ heading }
				placeholder={ __( 'Find your gallery', 'profotograaf' ) }
				onChange={ ( value ) => setAttributes( { heading: value } ) }
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
	);
}

function SettingsPanels( props: EditProps ) {
	return (
		<InspectorControls>
			<PortalPanel { ...props } />
			<TextPanel { ...props } />
		</InspectorControls>
	);
}

function Edit( { attributes, setAttributes }: EditProps ) {
	const blockProps = useBlockProps();
	const copy = previewCopy( attributes );

	return (
		<>
			<SettingsPanels
				attributes={ attributes }
				setAttributes={ setAttributes }
			/>
			<div { ...blockProps }>
				<h3 className="wp-block-profotograaf-client-galleries__heading">
					{ copy.heading }
				</h3>
				<p className="wp-block-profotograaf-client-galleries__text">
					{ copy.description }
				</p>
				<span className="wp-block-profotograaf-client-galleries__button">
					{ copy.buttonLabel }
				</span>
				{ copy.showNotice && (
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

registerServerRenderedBlock< ClientGalleriesAttributes >( metadata.name, {
	edit: Edit,
} );
