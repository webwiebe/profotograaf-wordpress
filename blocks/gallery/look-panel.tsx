import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { InspectorControls } from '@wordpress/block-editor';
import { BaseControl, ColorPalette, PanelBody, SelectControl, TextControl } from '@wordpress/components';
import { cleanNumber, type DisplayAttributes, type SiteDefaults } from './display-options';
import {
	GAP_CUSTOM,
	cleanColor,
	gapOptions,
	gapPreset,
	hoverOptions,
	lookScope,
	radiusOptions,
	shadowOptions,
	strengthOptions,
	type LookAttributes,
} from './look-options';

type Attributes = LookAttributes & Pick< DisplayAttributes, 'gap' | 'ratio' > & { layout?: string };

interface PanelProps {
	attributes: Attributes;
	setAttributes: ( next: Partial< LookAttributes & Pick< DisplayAttributes, 'gap' > > ) => void;
	defaults: SiteDefaults;
}

/** The frame colours the palette offers; any other hex code can be typed. */
const FRAME_COLORS = [
	{ name: 'White', color: '#ffffff' },
	{ name: 'Off-white', color: '#f4f1ea' },
	{ name: 'Grey', color: '#8a8a8a' },
	{ name: 'Black', color: '#111111' },
];

function NumberField( {
	label,
	help,
	value,
	max,
	onChange,
}: {
	label: string;
	help?: string;
	value: string;
	max: number;
	onChange: ( value: string ) => void;
} ) {
	return (
		<TextControl
			type="number"
			label={ label }
			help={ help ?? __( 'Leave empty for the site default.', 'profotograaf' ) }
			min={ 0 }
			max={ max }
			value={ value }
			onChange={ ( next ) => onChange( cleanNumber( next, 0, max ) ) }
			__nextHasNoMarginBottom
		/>
	);
}

/** The gap preset, with a number field for a custom gap. */
function GapControl( { gap, setGap }: { gap: string; setGap: ( gap: string ) => void } ) {
	const [ custom, setCustom ] = useState( gapPreset( gap ) === GAP_CUSTOM );
	const preset = custom ? GAP_CUSTOM : gapPreset( gap );
	return (
		<>
			<SelectControl
				label={ __( 'Gap between photos', 'profotograaf' ) }
				value={ preset }
				options={ gapOptions() }
				onChange={ ( next ) => {
					setCustom( next === GAP_CUSTOM );
					if ( next !== GAP_CUSTOM ) {
						setGap( next );
					}
				} }
				__nextHasNoMarginBottom
			/>
			{ preset === GAP_CUSTOM && (
				<NumberField label={ __( 'Custom gap (pixels)', 'profotograaf' ) } value={ gap } max={ 96 } onChange={ setGap } />
			) }
		</>
	);
}

/** The frame width and colour around each photo. */
function FrameControls( { attributes, setAttributes }: Omit< PanelProps, 'defaults' > ) {
	return (
		<>
			<NumberField
				label={ __( 'Frame width (pixels)', 'profotograaf' ) }
				help={ __( 'From 1 to 24 pixels; 0 means no frame. Leave empty for the site default.', 'profotograaf' ) }
				value={ attributes.frameWidth }
				max={ 24 }
				onChange={ ( frameWidth ) => setAttributes( { frameWidth } ) }
			/>
			<BaseControl
				id="profotograaf-frame-color"
				label={ __( 'Frame colour', 'profotograaf' ) }
				help={ __( 'White when only a width is set.', 'profotograaf' ) }
				__nextHasNoMarginBottom
			>
				<ColorPalette
					colors={ FRAME_COLORS }
					value={ attributes.frameColor }
					disableCustomColors={ false }
					clearable
					onChange={ ( color?: string ) => setAttributes( { frameColor: cleanColor( color ) } ) }
				/>
			</BaseControl>
		</>
	);
}

/** Inspector panel with the spacing and shape options (#198). */
export function LookPanel( { attributes, setAttributes, defaults }: PanelProps ) {
	const { slideshow, square } = lookScope( attributes, defaults );
	const select = ( key: keyof LookAttributes, label: string, options: Array< { label: string; value: string } > ) => (
		<SelectControl
			label={ label }
			value={ attributes[ key ] }
			options={ options }
			onChange={ ( value ) => setAttributes( { [ key ]: value } ) }
			__nextHasNoMarginBottom
		/>
	);
	const gapHelp = __( 'Leave empty to use the gap between photos.', 'profotograaf' );
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Spacing and shape', 'profotograaf' ) } initialOpen={ false }>
				<GapControl gap={ attributes.gap } setGap={ ( gap ) => setAttributes( { gap } ) } />
				{ ! slideshow && (
					<>
						<NumberField label={ __( 'Space between rows (pixels)', 'profotograaf' ) } help={ gapHelp } value={ attributes.gapRow } max={ 96 } onChange={ ( gapRow ) => setAttributes( { gapRow } ) } />
						<NumberField label={ __( 'Space between columns (pixels)', 'profotograaf' ) } help={ gapHelp } value={ attributes.gapColumn } max={ 96 } onChange={ ( gapColumn ) => setAttributes( { gapColumn } ) } />
					</>
				) }
				{ select( 'radius', __( 'Corners', 'profotograaf' ), radiusOptions( square, attributes.radius ) ) }
				{ select( 'hover', __( 'Hover effect', 'profotograaf' ), hoverOptions( slideshow ) ) }
				{ attributes.hover !== 'none' && select( 'hoverStrength', __( 'Hover strength', 'profotograaf' ), strengthOptions() ) }
				{ ! slideshow && <FrameControls attributes={ attributes } setAttributes={ setAttributes } /> }
				{ ! slideshow && select( 'shadow', __( 'Shadow', 'profotograaf' ), shadowOptions() ) }
			</PanelBody>
		</InspectorControls>
	);
}
