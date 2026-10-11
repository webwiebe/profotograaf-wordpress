import { __ } from '@wordpress/i18n';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { displayControls } from '../gallery/display-options';
import type { FullPageAttributes } from './types';

type DisplayKey = 'captions' | 'lightbox' | 'linkTo' | 'linkNewTab';

/** The display options of the gallery block that the full-page gallery has. */
const SHARED: DisplayKey[] = [ 'captions', 'lightbox', 'linkTo', 'linkNewTab' ];

function isShared( attribute: string ): attribute is DisplayKey {
	return ( SHARED as string[] ).includes( attribute );
}

/**
 * The Display panel: captions, lightbox and link, with the controls and
 * choices of the gallery block (display-options.ts). Every option starts on
 * the site default. A section can still set its own captions.
 */
export function DisplayPanel( {
	attributes,
	setAttributes,
}: {
	attributes: FullPageAttributes;
	setAttributes: ( next: Partial< FullPageAttributes > ) => void;
} ) {
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Display', 'profotograaf' ) } initialOpen={ false }>
				{ displayControls( attributes ).map( ( control ) => {
					const attribute = control.attribute;
					return isShared( attribute ) ? (
						<SelectControl
							key={ attribute }
							label={ control.label }
							help={ control.help }
							value={ attributes[ attribute ] }
							options={ control.options ?? [] }
							onChange={ ( value ) => setAttributes( { [ attribute ]: value } ) }
							__nextHasNoMarginBottom
						/>
					) : null;
				} ) }
			</PanelBody>
		</InspectorControls>
	);
}
