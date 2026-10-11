import { __, sprintf } from '@wordpress/i18n';
import { InspectorControls } from '@wordpress/block-editor';
import { Button, PanelBody, SelectControl } from '@wordpress/components';
import type { PhotoRow } from '../gallery/types';
import { presetOptions, typeLabel } from './labels';
import { isCustom } from './picks';
import { SectionFields } from './section-fields';
import { moved, removed, replaced, seedSections, withType } from './section-list';
import { DEFAULT_PRESET, PRESETS, SECTION_TYPES, isSectionType, type PresetId, type Section } from './sections';
import { SlotPicker } from './slot-picker';
import type { FullPageAttributes, SectionsAnswer } from './types';

interface PanelProps {
	attributes: FullPageAttributes;
	setAttributes: ( next: Partial< FullPageAttributes > ) => void;
	answer: SectionsAnswer | null;
	photos: PhotoRow[] | null;
}

interface CardProps {
	section: Section;
	index: number;
	count: number;
	onChange: ( sections: ( list: Section[] ) => Section[] ) => void;
}

/** One section of the Custom list: its type, its fields and the buttons that move or remove it. */
function SectionCard( { section, index, count, onChange }: CardProps ) {
	return (
		<div className="profotograaf-fp-section">
			<p className="profotograaf-fp-section__title">
				{ sprintf(
					/* translators: 1: position of the section in the list, 2: section type such as Grid. */
					__( 'Section %1$d: %2$s', 'profotograaf' ),
					index + 1,
					typeLabel( section.type )
				) }
			</p>
			<SelectControl
				label={ __( 'Type', 'profotograaf' ) }
				value={ section.type }
				options={ SECTION_TYPES.map( ( type ) => ( { label: typeLabel( type ), value: type } ) ) }
				onChange={ ( type ) => {
					if ( isSectionType( type ) ) {
						onChange( ( list ) => replaced( list, index, withType( section, type ) ) );
					}
				} }
				__nextHasNoMarginBottom
			/>
			<SectionFields section={ section } onChange={ ( next ) => onChange( ( list ) => replaced( list, index, next ) ) } />
			<div className="profotograaf-fp-section__actions">
				{ index > 0 && (
					<Button variant="secondary" onClick={ () => onChange( ( list ) => moved( list, index, -1 ) ) }>
						{ __( 'Move up', 'profotograaf' ) }
					</Button>
				) }
				{ index < count - 1 && (
					<Button variant="secondary" onClick={ () => onChange( ( list ) => moved( list, index, 1 ) ) }>
						{ __( 'Move down', 'profotograaf' ) }
					</Button>
				) }
				<Button variant="link" isDestructive onClick={ () => onChange( ( list ) => removed( list, index ) ) }>
					{ __( 'Remove', 'profotograaf' ) }
				</Button>
			</div>
		</div>
	);
}

/** The photographer's own list of sections, used by the Custom preset. */
function SectionList( { attributes, setAttributes }: Pick< PanelProps, 'attributes' | 'setAttributes' > ) {
	const { sections } = attributes;
	const change = ( edit: ( list: Section[] ) => Section[] ) => setAttributes( { sections: edit( sections ) } );
	return (
		<div className="profotograaf-fp-sections">
			{ sections.length === 0 && (
				<p className="profotograaf-fp-help">
					{ __( 'Add a section. Without a section that shows photos, the gallery uses the default preset.', 'profotograaf' ) }
				</p>
			) }
			{ sections.map( ( section, index ) => (
				<SectionCard key={ index } section={ section } index={ index } count={ sections.length } onChange={ change } />
			) ) }
			<Button variant="secondary" onClick={ () => change( ( list ) => [ ...list, { type: 'grid' } ] ) }>
				{ __( 'Add a section', 'profotograaf' ) }
			</Button>
		</div>
	);
}

/** Whether a stored value names a preset of includes/sections.json. */
function isPreset( value: string ): value is PresetId {
	return ( PRESETS as string[] ).includes( value );
}

/** The preset a Custom list starts from: the one the block used before. */
function startingPreset( preset: string ): PresetId {
	return isPreset( preset ) ? preset : DEFAULT_PRESET;
}

/**
 * The Layout panel: the preset, the section list in Custom, then the picks
 * for the hero, feature and pair slots.
 */
export function SectionsPanel( { attributes, setAttributes, answer, photos }: PanelProps ) {
	const choosePreset = ( preset: string ) => {
		// A first switch to Custom starts from the sections the block showed.
		const seed = preset === 'custom' && attributes.sections.length === 0;
		setAttributes( seed ? { preset, sections: seedSections( startingPreset( attributes.preset ) ) } : { preset } );
	};
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Layout', 'profotograaf' ) }>
				<SelectControl
					label={ __( 'Preset', 'profotograaf' ) }
					help={ __( 'The pattern of sections repeats until every photo is shown.', 'profotograaf' ) }
					value={ attributes.preset }
					options={ presetOptions() }
					onChange={ choosePreset }
					__nextHasNoMarginBottom
				/>
				{ isCustom( attributes ) && <SectionList attributes={ attributes } setAttributes={ setAttributes } /> }
				<SlotPicker attributes={ attributes } setAttributes={ setAttributes } answer={ answer } photos={ photos } />
			</PanelBody>
		</InspectorControls>
	);
}
