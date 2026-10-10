import { __ } from '@wordpress/i18n';
import { InspectorControls } from '@wordpress/block-editor';
import { CheckboxControl, PanelBody, SelectControl, TextControl } from '@wordpress/components';
import {
	AUTO,
	blockDefaults,
	cleanNumber,
	displayControls,
	siteDefaults,
	type DisplayAttributes,
	type DisplayControl,
} from './display-options';
import { platformDefaults } from './layout-map';
import type { GalleryRow } from './types';

interface PanelProps {
	attributes: DisplayAttributes & { layout?: string };
	setAttributes: ( next: Partial< DisplayAttributes > ) => void;
	/** The chosen gallery's list row, for the layout the platform default draws. */
	row?: GalleryRow | null;
}

/**
 * A number option, with the Automatic box below it when the option has one:
 * the box stores `auto`, which ignores the site default.
 */
function NumberControl( {
	control,
	value,
	setAttributes,
}: {
	control: DisplayControl;
	value: string;
	setAttributes: PanelProps[ 'setAttributes' ];
} ) {
	const automatic = value === AUTO;
	return (
		<div>
			<TextControl
				type="number"
				label={ control.label }
				help={ __( 'Leave empty for the site default.', 'profotograaf' ) }
				placeholder={ control.placeholder }
				min={ control.min }
				max={ control.max }
				disabled={ automatic }
				value={ automatic ? '' : value }
				onChange={ ( next ) =>
					setAttributes( {
						[ control.attribute ]: cleanNumber( next, control.min ?? 0, control.max ?? 999 ),
					} )
				}
				__nextHasNoMarginBottom
			/>
			{ control.automatic && (
				<CheckboxControl
					label={ control.automatic }
					help={ __( 'Ignores the site default.', 'profotograaf' ) }
					checked={ automatic }
					onChange={ ( checked ) => setAttributes( { [ control.attribute ]: checked ? AUTO : '' } ) }
					__nextHasNoMarginBottom
				/>
			) }
		</div>
	);
}

/** Inspector panel with one control per display option. */
export function DisplayPanel( { attributes, setAttributes, row = null }: PanelProps ) {
	return (
		<InspectorControls>
			<PanelBody
				title={ __( 'Display', 'profotograaf' ) }
				initialOpen={ false }
			>
				{ displayControls( attributes, platformDefaults( blockDefaults( attributes, siteDefaults() ), row ) ).map( ( control ) =>
					control.kind === 'note' ? (
						<p key={ control.attribute } className="profotograaf-photo-grid__help">
							{ control.help }
						</p>
					) : control.kind === 'select' ? (
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
						<NumberControl
							key={ control.attribute }
							control={ control }
							value={ attributes[ control.attribute ] }
							setAttributes={ setAttributes }
						/>
					)
				) }
			</PanelBody>
		</InspectorControls>
	);
}
