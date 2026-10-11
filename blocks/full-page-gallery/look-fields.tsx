import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import { BaseControl, ColorPalette, SelectControl, TextControl } from '@wordpress/components';
import { cleanNumber } from '../gallery/display-options';
import { GAP_CUSTOM, cleanColor, gapPreset } from '../gallery/look-options';
import { fieldLabel } from './labels';
import { LOOK_FIELDS, drawnValue, gapChoices, lookChoices, type LookField } from './look';
import { withField } from './section-list';
import { fieldRule, sectionFields, type Section } from './sections';

interface FieldProps {
	section: Section;
	field: LookField;
	onChange: ( section: Section ) => void;
}

/** The frame colours the palette offers, as in the gallery block; any other hex code can be typed. */
const FRAME_COLORS = [
	{ name: 'White', color: '#ffffff' },
	{ name: 'Off-white', color: '#f4f1ea' },
	{ name: 'Grey', color: '#8a8a8a' },
	{ name: 'Black', color: '#111111' },
];

/** A number of pixels in the range of includes/sections.json; empty keeps the default. */
function PixelField( { section, field, onChange, label }: FieldProps & { label: string } ) {
	const rule = fieldRule( section.type, field );
	const value = section[ field ];
	const max = rule.max ?? 96;
	return (
		<TextControl
			type="number"
			label={ label }
			help={ sprintf(
				/* translators: 1: lowest allowed number, 2: highest allowed number. */
				__( 'From %1$d to %2$d.', 'profotograaf' ),
				rule.min ?? 0,
				max
			) }
			value={ typeof value === 'number' ? String( value ) : '' }
			placeholder={ typeof rule.default === 'number' ? String( rule.default ) : '' }
			min={ rule.min ?? 0 }
			max={ max }
			onChange={ ( raw ) => {
				const clean = cleanNumber( raw, 0, max );
				onChange( withField( section, field, clean === '' ? undefined : Number( clean ) ) );
			} }
			__nextHasNoMarginBottom
		/>
	);
}

/** A gap: the default, a preset of the gallery block, or a custom number of pixels. */
function GapField( props: FieldProps & { field: 'gap' | 'gapRow' | 'gapColumn' } ) {
	const { section, field, onChange } = props;
	const stored = typeof section[ field ] === 'number' ? String( section[ field ] ) : '';
	const [ custom, setCustom ] = useState( gapPreset( stored ) === GAP_CUSTOM );
	const preset = custom ? GAP_CUSTOM : gapPreset( stored );
	return (
		<>
			<SelectControl
				label={ fieldLabel( field ) }
				value={ preset }
				options={ gapChoices( section, field ) }
				onChange={ ( next ) => {
					setCustom( next === GAP_CUSTOM );
					if ( next !== GAP_CUSTOM ) {
						onChange( withField( section, field, next === '' ? undefined : Number( next ) ) );
					}
				} }
				__nextHasNoMarginBottom
			/>
			{ preset === GAP_CUSTOM && <PixelField { ...props } label={ __( 'Custom gap (pixels)', 'profotograaf' ) } /> }
		</>
	);
}

function ChoiceField( { section, field, onChange }: FieldProps & { field: 'radius' | 'hover' | 'hoverStrength' | 'shadow' } ) {
	const value = section[ field ];
	return (
		<SelectControl
			label={ fieldLabel( field ) }
			value={ typeof value === 'string' ? value : '' }
			options={ lookChoices( section, field ) }
			onChange={ ( next ) => onChange( withField( section, field, next || undefined ) ) }
			__nextHasNoMarginBottom
		/>
	);
}

function ColorField( { section, onChange }: Omit< FieldProps, 'field' > ) {
	return (
		<BaseControl
			id="profotograaf-fp-frame-color"
			label={ fieldLabel( 'frameColor' ) }
			help={ __( 'White when only a width is set.', 'profotograaf' ) }
			__nextHasNoMarginBottom
		>
			<ColorPalette
				colors={ FRAME_COLORS }
				value={ section.frameColor ?? '' }
				disableCustomColors={ false }
				clearable
				onChange={ ( color?: string ) => onChange( withField( section, 'frameColor', cleanColor( color ) || undefined ) ) }
			/>
		</BaseControl>
	);
}

function LookControl( props: FieldProps ) {
	const { field } = props;
	switch ( field ) {
		case 'gap':
		case 'gapRow':
		case 'gapColumn':
			return <GapField { ...props } field={ field } />;
		case 'frameWidth':
			return <PixelField { ...props } label={ fieldLabel( field ) } />;
		case 'frameColor':
			return <ColorField section={ props.section } onChange={ props.onChange } />;
		case 'hoverStrength':
			return drawnValue( props.section, 'hover' ) === 'none' ? null : <ChoiceField { ...props } field={ field } />;
		default:
			return <ChoiceField { ...props } field={ field } />;
	}
}

/** The spacing and shape fields a section type has, under their own heading. */
export function LookFields( { section, onChange }: Omit< FieldProps, 'field' > ) {
	const own = sectionFields( section.type ) as string[];
	const fields = LOOK_FIELDS.filter( ( field ) => own.includes( field ) );
	if ( fields.length === 0 ) {
		return null;
	}
	return (
		<>
			<p className="profotograaf-fp-section__subtitle">{ __( 'Spacing and shape', 'profotograaf' ) }</p>
			{ fields.map( ( field ) => (
				<LookControl key={ field } section={ section } field={ field } onChange={ onChange } />
			) ) }
		</>
	);
}
