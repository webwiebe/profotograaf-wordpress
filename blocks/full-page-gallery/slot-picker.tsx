import { __, sprintf } from '@wordpress/i18n';
import { Button } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { visiblePhotos } from '../gallery/exclude';
import type { PhotoRow } from '../gallery/types';
import { typeLabel } from './labels';
import { choosePhoto, clearSlot, slotGroups, type Slot } from './picks';
import type { FullPageAttributes, SectionsAnswer } from './types';

interface PickerProps {
	attributes: FullPageAttributes;
	setAttributes: ( next: Partial< FullPageAttributes > ) => void;
	answer: SectionsAnswer | null;
	/** The photos of the gallery, null while they load. */
	photos: PhotoRow[] | null;
}

/** The photos a slot can take: the gallery without the excluded photos. */
function Choices( {
	photos,
	current,
	onChoose,
}: {
	photos: PhotoRow[];
	current: string;
	onChoose: ( photoId: string ) => void;
} ) {
	return (
		<ul className="profotograaf-fp-choices">
			{ photos.map( ( photo ) => (
				<li key={ photo.id }>
					<button
						type="button"
						className="profotograaf-fp-choice"
						aria-pressed={ photo.id === current }
						aria-label={ photo.title || photo.alt || photo.id }
						onClick={ () => onChoose( photo.id ) }
					>
						{ photo.thumb_url ? <img src={ photo.thumb_url } alt="" /> : <span /> }
					</button>
				</li>
			) ) }
		</ul>
	);
}

function SlotRow( {
	slot,
	label,
	open,
	onToggle,
	onClear,
}: {
	slot: Slot;
	label: string;
	open: boolean;
	onToggle: () => void;
	onClear: () => void;
} ) {
	return (
		<div className="profotograaf-fp-slot">
			{ slot.photo?.thumb ? (
				<img className="profotograaf-fp-slot__thumb" src={ slot.photo.thumb } alt="" />
			) : (
				<span className="profotograaf-fp-slot__thumb" />
			) }
			<span className="profotograaf-fp-slot__label">
				{ label }
				{ slot.picked ? ` (${ __( 'picked', 'profotograaf' ) })` : '' }
			</span>
			<Button variant="secondary" onClick={ onToggle } aria-expanded={ open }>
				{ open ? __( 'Close', 'profotograaf' ) : __( 'Choose photo', 'profotograaf' ) }
			</Button>
			{ slot.picked && (
				<Button variant="link" onClick={ onClear }>
					{ __( 'Clear', 'profotograaf' ) }
				</Button>
			) }
		</div>
	);
}

/** The name of one slot, such as "Hero photo 2" or "Large photos in a row 1, photo 3". */
function slotLabel( type: string, group: number, position: number, size: number ): string {
	if ( size === 1 ) {
		return sprintf(
			/* translators: 1: section type such as Hero photo, 2: its number in the gallery. */
			__( '%1$s %2$d', 'profotograaf' ),
			type,
			group
		);
	}
	return sprintf(
		/* translators: 1: section type such as Hero photo, 2: its number in the gallery, 3: photo position in it. */
		__( '%1$s %2$d, photo %3$d', 'profotograaf' ),
		type,
		group,
		position
	);
}

/**
 * Picks for the hero, feature and pair slots of the preview. Choosing a photo
 * that sits in another slot moves it there: one photo, one slot.
 */
export function SlotPicker( { attributes, setAttributes, answer, photos }: PickerProps ) {
	const [ open, setOpen ] = useState( '' );
	const groups = answer ? slotGroups( attributes, answer ) : [];
	if ( groups.length === 0 ) {
		return null;
	}
	const choices = visiblePhotos( photos ?? [], attributes.excludedPhotoIds );
	return (
		<div className="profotograaf-fp-slots">
			<p className="profotograaf-fp-help">
				{ __( 'Pick the photos for the large slots. A photo shows once, in the slot it was last picked for.', 'profotograaf' ) }
			</p>
			{ groups.map( ( group ) =>
				group.slots.map( ( slot ) => (
					<div key={ slot.id }>
						<SlotRow
							slot={ slot }
							label={ slotLabel( typeLabel( group.type ), group.number, slot.position + 1, group.slots.length ) }
							open={ open === slot.id }
							onToggle={ () => setOpen( open === slot.id ? '' : slot.id ) }
							onClear={ () => setAttributes( clearSlot( attributes, slot ) ) }
						/>
						{ open === slot.id && (
							<Choices
								photos={ choices }
								current={ slot.picked }
								onChoose={ ( photoId ) => {
									setAttributes( choosePhoto( attributes, slot, photoId ) );
									setOpen( '' );
								} }
							/>
						) }
					</div>
				) )
			) }
		</div>
	);
}
