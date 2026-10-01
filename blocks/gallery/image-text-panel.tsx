import { __ } from '@wordpress/i18n';
import { InspectorControls } from '@wordpress/block-editor';
import { Button, PanelBody, TextControl } from '@wordpress/components';
import type { ImageText } from './types';

interface PanelProps {
	items: ImageText[];
	setItems: ( next: ImageText[] ) => void;
}

/** One entry changed, as a new list. */
function withChange(
	items: ImageText[],
	index: number,
	change: Partial< ImageText >
): ImageText[] {
	return items.map( ( item, at ) =>
		at === index ? { ...item, ...change } : item
	);
}

/** The list without one entry. */
function withoutEntry( items: ImageText[], index: number ): ImageText[] {
	return items.filter( ( _item, at ) => at !== index );
}

function PhotoRow( {
	item,
	onChange,
	onRemove,
}: {
	item: ImageText;
	onChange: ( change: Partial< ImageText > ) => void;
	onRemove: () => void;
} ) {
	return (
		<div className="profotograaf-image-text">
			<TextControl
				label={ __( 'Photo ID', 'profotograaf' ) }
				value={ item.id }
				onChange={ ( id ) => onChange( { id } ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Caption', 'profotograaf' ) }
				value={ item.caption ?? '' }
				onChange={ ( caption ) => onChange( { caption } ) }
				__nextHasNoMarginBottom
			/>
			<TextControl
				label={ __( 'Alt text', 'profotograaf' ) }
				value={ item.alt ?? '' }
				onChange={ ( alt ) => onChange( { alt } ) }
				__nextHasNoMarginBottom
			/>
			<Button variant="link" isDestructive onClick={ onRemove }>
				{ __( 'Remove', 'profotograaf' ) }
			</Button>
		</div>
	);
}

/**
 * Inspector panel for the caption and alt text of single photos. A photo is
 * found by its Profotograaf photo id, shown in the photo's details there.
 */
export function ImageTextPanel( { items, setItems }: PanelProps ) {
	return (
		<InspectorControls>
			<PanelBody
				title={ __( 'Photo captions and alt text', 'profotograaf' ) }
				initialOpen={ false }
			>
				{ items.map( ( item, index ) => (
					<PhotoRow
						key={ index }
						item={ item }
						onChange={ ( change ) =>
							setItems( withChange( items, index, change ) )
						}
						onRemove={ () => setItems( withoutEntry( items, index ) ) }
					/>
				) ) }
				<Button
					variant="secondary"
					onClick={ () => setItems( [ ...items, { id: '' } ] ) }
				>
					{ __( 'Add a photo', 'profotograaf' ) }
				</Button>
			</PanelBody>
		</InspectorControls>
	);
}
