import { __, sprintf } from '@wordpress/i18n';
import { SelectControl, TextControl, TextareaControl } from '@wordpress/components';
import { cleanNumber } from '../gallery/display-options';
import { defaultLabel, fieldLabel, valueLabel, type FieldName } from './labels';
import { withField } from './section-list';
import { fieldRule, sectionFields, type Section } from './sections';

interface FieldProps {
	section: Section;
	field: FieldName;
	onChange: ( section: Section ) => void;
}

/** A field with a list of allowed values, such as the photo shape. */
function ChoiceField( { section, field, onChange }: FieldProps ) {
	const rule = fieldRule( section.type, field );
	const value = section[ field ];
	return (
		<SelectControl
			label={ fieldLabel( field ) }
			value={ typeof value === 'string' ? value : '' }
			options={ [
				{ label: defaultLabel( valueLabel( String( rule.default ) ) ), value: '' },
				...( rule.enum ?? [] ).map( ( option ) => ( { label: valueLabel( option ), value: option } ) ),
			] }
			onChange={ ( next ) => onChange( withField( section, field, next || undefined ) ) }
			__nextHasNoMarginBottom
		/>
	);
}

/** An on or off field (mirror), stored only once the photographer sets it. */
function SwitchField( { section, field, onChange }: FieldProps ) {
	const value = section[ field ];
	const on = __( 'On', 'profotograaf' );
	const off = __( 'Off', 'profotograaf' );
	return (
		<SelectControl
			label={ fieldLabel( field ) }
			value={ typeof value === 'boolean' ? ( value ? 'on' : 'off' ) : '' }
			options={ [
				{ label: defaultLabel( fieldRule( section.type, field ).default ? on : off ), value: '' },
				{ label: on, value: 'on' },
				{ label: off, value: 'off' },
			] }
			onChange={ ( next ) => onChange( withField( section, field, next === '' ? undefined : next === 'on' ) ) }
			__nextHasNoMarginBottom
		/>
	);
}

/**
 * A whole number with the range of includes/sections.json. A value below the
 * minimum is kept while the photographer types (360 starts as 3), and the
 * renderer raises it to the minimum.
 */
function NumberField( { section, field, onChange }: FieldProps ) {
	const rule = fieldRule( section.type, field );
	const value = section[ field ];
	const min = rule.min ?? 0;
	const max = rule.max ?? 9999;
	return (
		<TextControl
			type="number"
			label={ fieldLabel( field ) }
			help={ sprintf(
				/* translators: 1: lowest allowed number, 2: highest allowed number. */
				__( 'From %1$d to %2$d.', 'profotograaf' ),
				min,
				max
			) }
			value={ typeof value === 'number' ? String( value ) : '' }
			placeholder={ String( rule.default ) }
			min={ min }
			max={ max }
			onChange={ ( raw ) => {
				const clean = cleanNumber( raw, 0, max );
				onChange( withField( section, field, clean === '' ? undefined : Number( clean ) ) );
			} }
			__nextHasNoMarginBottom
		/>
	);
}

/** A free text field: the hero title, the heading or the text of a text section. */
function TextField( { section, field, onChange }: FieldProps ) {
	const value = section[ field ];
	const Control = field === 'text' ? TextareaControl : TextControl;
	return (
		<Control
			label={ fieldLabel( field ) }
			value={ typeof value === 'string' ? value : '' }
			onChange={ ( next: string ) => onChange( withField( section, field, next || undefined ) ) }
			__nextHasNoMarginBottom
		/>
	);
}

/** The control for one field, chosen by the rule of the field. */
function Field( props: FieldProps ) {
	const rule = fieldRule( props.section.type, props.field );
	if ( rule.enum ) {
		return <ChoiceField { ...props } />;
	}
	if ( typeof rule.default === 'boolean' ) {
		return <SwitchField { ...props } />;
	}
	if ( typeof rule.default === 'number' ) {
		return <NumberField { ...props } />;
	}
	return <TextField { ...props } />;
}

/** The fields of one section, in the order of includes/sections.json. */
export function SectionFields( { section, onChange }: Omit< FieldProps, 'field' > ) {
	return (
		<>
			{ sectionFields( section.type ).map( ( field ) => (
				<Field key={ field } section={ section } field={ field } onChange={ onChange } />
			) ) }
		</>
	);
}
