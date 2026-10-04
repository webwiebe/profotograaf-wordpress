import { __ } from '@wordpress/i18n';
import { InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import {
	cleanNumber,
	displayControls,
	type DisplayAttributes,
} from './display-options';

interface PanelProps {
	attributes: DisplayAttributes;
	setAttributes: ( next: Partial< DisplayAttributes > ) => void;
}

/** Inspector panel with one control per display option. */
export function DisplayPanel( { attributes, setAttributes }: PanelProps ) {
	return (
		<InspectorControls>
			<PanelBody
				title={ __( 'Display', 'profotograaf' ) }
				initialOpen={ false }
			>
				{ displayControls().map( ( control ) =>
					control.kind === 'select' ? (
						<SelectControl
							key={ control.attribute }
							label={ control.label }
							help={ control.help }
							value={ attributes[ control.attribute ] }
							options={ control.options ?? [] }
							onChange={ ( value ) =>
								setAttributes( { [ control.attribute ]: value } )
							}
							__nextHasNoMarginBottom
						/>
					) : (
						<TextControl
							key={ control.attribute }
							type="number"
							label={ control.label }
							help={ __( 'Leave empty for the site default.', 'profotograaf' ) }
							min={ control.min }
							max={ control.max }
							value={ attributes[ control.attribute ] }
							onChange={ ( value ) =>
								setAttributes( {
									[ control.attribute ]: cleanNumber(
										value,
										control.min ?? 0,
										control.max ?? 999
									),
								} )
							}
							__nextHasNoMarginBottom
						/>
					)
				) }
			</PanelBody>
		</InspectorControls>
	);
}
