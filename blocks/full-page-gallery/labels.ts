import { __, sprintf } from '@wordpress/i18n';
import { DEFAULT_PRESET, PRESETS, type SectionType, type sectionFields } from './sections';

/** The name of one section field, as sectionFields() lists it. */
export type FieldName = ReturnType< typeof sectionFields >[ number ];

/** The name of a preset, or the id when the editor does not know it. */
function presetLabel( preset: string ): string {
	const labels: Record< string, string > = {
		calm: __( 'Calm', 'profotograaf' ),
		editorial: __( 'Editorial', 'profotograaf' ),
		playful: __( 'Playful', 'profotograaf' ),
		custom: __( 'Custom', 'profotograaf' ),
	};
	return labels[ preset ] ?? preset;
}

/**
 * The preset choices: the default (stored as ''), every preset of
 * includes/sections.json and Custom.
 */
export function presetOptions(): Array< { label: string; value: string } > {
	return [
		{ label: defaultLabel( presetLabel( DEFAULT_PRESET ) ), value: '' },
		...[ ...PRESETS, 'custom' ].map( ( value ) => ( { label: presetLabel( value ), value } ) ),
	];
}

/** The name of a section type. */
export function typeLabel( type: SectionType ): string {
	const labels: Record< SectionType, string > = {
		grid: __( 'Grid', 'profotograaf' ),
		masonry: __( 'Masonry', 'profotograaf' ),
		feature: __( 'Large photos in a row', 'profotograaf' ),
		hero: __( 'Hero photo', 'profotograaf' ),
		pair: __( 'One large and two small photos', 'profotograaf' ),
		filmstrip: __( 'Filmstrip', 'profotograaf' ),
		text: __( 'Text', 'profotograaf' ),
	};
	return labels[ type ];
}

/** The label of a section field. */
export function fieldLabel( field: FieldName ): string {
	const labels: Record< FieldName, string > = {
		columns: __( 'Columns', 'profotograaf' ),
		rows: __( 'Rows', 'profotograaf' ),
		count: __( 'Number of photos', 'profotograaf' ),
		ratio: __( 'Photo shape', 'profotograaf' ),
		gap: __( 'Space between photos (px)', 'profotograaf' ),
		captions: __( 'Captions', 'profotograaf' ),
		height: __( 'Height (% of the screen height)', 'profotograaf' ),
		rowHeight: __( 'Row height (px)', 'profotograaf' ),
		side: __( 'Side of the large photo', 'profotograaf' ),
		mirror: __( 'Swap sides on every second pair', 'profotograaf' ),
		title: __( 'Title over the photo', 'profotograaf' ),
		heading: __( 'Heading', 'profotograaf' ),
		text: __( 'Text', 'profotograaf' ),
		align: __( 'Alignment', 'profotograaf' ),
	};
	return labels[ field ];
}

/** The label of one allowed value of a field. */
export function valueLabel( value: string ): string {
	const labels: Record< string, string > = {
		original: __( 'Original', 'profotograaf' ),
		'1-1': __( 'Square (1:1)', 'profotograaf' ),
		off: __( 'Hidden', 'profotograaf' ),
		below: __( 'Below the photo', 'profotograaf' ),
		overlay: __( 'On top of the photo', 'profotograaf' ),
		left: __( 'Left', 'profotograaf' ),
		center: __( 'Center', 'profotograaf' ),
		right: __( 'Right', 'profotograaf' ),
	};
	return labels[ value ] ?? value.replace( '-', ':' );
}

/** The label of the choice that keeps a field on its default. */
export function defaultLabel( value: string ): string {
	return sprintf(
		/* translators: %s: the default value of a setting, such as 3:2 or Editorial. */
		__( 'Default (%s)', 'profotograaf' ),
		value
	);
}
