import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	Placeholder,
	SelectControl,
	TextControl,
	TextareaControl,
	ToggleControl,
} from '@wordpress/components';
import { createElement } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import './style.css';
import { registerServerRenderedBlock } from '../register';
import metadata from './block.json';
import { GalleryChooser, GalleryPanel } from './gallery-panel';
import {
	defaultCopy,
	galleryMode,
	headingTag,
	isGalleryUrl,
	previewCopy,
} from './helpers';
import type { ClientGalleriesAttributes } from './helpers';

interface EditProps {
	attributes: ClientGalleriesAttributes;
	setAttributes: ( next: Partial< ClientGalleriesAttributes > ) => void;
}

function headingOptions(): { label: string; value: string }[] {
	return [
		{ label: __( 'Heading 2', 'profotograaf' ), value: '2' },
		{ label: __( 'Heading 3', 'profotograaf' ), value: '3' },
		{ label: __( 'Heading 4', 'profotograaf' ), value: '4' },
		{ label: __( 'Heading 5', 'profotograaf' ), value: '5' },
		{ label: __( 'Heading 6', 'profotograaf' ), value: '6' },
		{ label: __( 'Paragraph', 'profotograaf' ), value: '0' },
	];
}

function PortalPanel( { attributes, setAttributes }: EditProps ) {
	const { portal, portalPath, openInNewTab } = attributes;
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
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Portal path', 'profotograaf' ) }
				help={ __(
					'Where the portal is on that address. Leave empty to use the site setting, which is /client unless you changed it.',
					'profotograaf'
				) }
				value={ portalPath }
				placeholder="/client"
				onChange={ ( value ) => setAttributes( { portalPath: value } ) }
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
	const { heading, headingLevel, description, buttonLabel } = attributes;
	const defaults = defaultCopy( galleryMode( attributes ) );
	return (
		<PanelBody title={ __( 'Text', 'profotograaf' ) } initialOpen={ false }>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Heading', 'profotograaf' ) }
				value={ heading }
				placeholder={ defaults.heading }
				onChange={ ( value ) => setAttributes( { heading: value } ) }
			/>
			<SelectControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Heading level', 'profotograaf' ) }
				value={ String( headingLevel ) }
				options={ headingOptions() }
				onChange={ ( value ) =>
					setAttributes( { headingLevel: Number( value ) } )
				}
			/>
			<TextareaControl
				__nextHasNoMarginBottom
				label={ __( 'Text', 'profotograaf' ) }
				value={ description }
				placeholder={ defaults.description }
				onChange={ ( value ) =>
					setAttributes( { description: value } )
				}
			/>
			<TextControl
				__nextHasNoMarginBottom
				__next40pxDefaultSize
				label={ __( 'Button label', 'profotograaf' ) }
				value={ buttonLabel }
				placeholder={ defaults.buttonLabel }
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
			<GalleryPanel { ...props } />
			{ ! galleryMode( props.attributes ) && <PortalPanel { ...props } /> }
			<TextPanel { ...props } />
		</InspectorControls>
	);
}

function Preview( { attributes }: { attributes: ClientGalleriesAttributes } ) {
	const copy = previewCopy( attributes );
	return (
		<>
			{ createElement(
				headingTag( attributes.headingLevel ),
				{ className: 'wp-block-profotograaf-client-galleries__heading' },
				copy.heading
			) }
			<p className="wp-block-profotograaf-client-galleries__text">
				{ copy.description }
			</p>
			<div className="wp-block-button">
				<span className="wp-block-profotograaf-client-galleries__button wp-block-button__link wp-element-button">
					{ copy.buttonLabel }
				</span>
			</div>
			{ copy.notice && (
				<p className="wp-block-profotograaf-client-galleries__notice">
					{ copy.notice }
				</p>
			) }
		</>
	);
}

function Edit( { attributes, setAttributes }: EditProps ) {
	const blockProps = useBlockProps();
	const asksForGallery =
		attributes.linkTo === 'gallery' && ! isGalleryUrl( attributes.gallery );

	return (
		<>
			<SettingsPanels
				attributes={ attributes }
				setAttributes={ setAttributes }
			/>
			<div { ...blockProps }>
				{ asksForGallery ? (
					<Placeholder
						icon="format-gallery"
						label={ __( 'Open a delivered gallery', 'profotograaf' ) }
						instructions={ __(
							'Choose the gallery this button opens. Visitors go to its share link in the same tab.',
							'profotograaf'
						) }
					>
						<GalleryChooser setAttributes={ setAttributes } />
					</Placeholder>
				) : (
					<Preview attributes={ attributes } />
				) }
			</div>
		</>
	);
}

registerServerRenderedBlock< ClientGalleriesAttributes >( metadata.name, {
	edit: Edit,
} );
