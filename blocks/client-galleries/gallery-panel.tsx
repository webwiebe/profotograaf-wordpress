import { Button, Notice, PanelBody } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { Picker } from '../gallery/picker';
import type { GalleryRow } from '../gallery/types';
import { isGalleryUrl } from './helpers';
import type { ClientGalleriesAttributes } from './helpers';

interface PanelProps {
	attributes: ClientGalleriesAttributes;
	setAttributes: ( next: Partial< ClientGalleriesAttributes > ) => void;
}

/**
 * Picks a gallery from the connected account and stores its share link. A
 * row without a usable link stores nothing and shows a note.
 */
export function GalleryChooser( {
	setAttributes,
	onDone,
}: {
	setAttributes: PanelProps[ 'setAttributes' ];
	onDone?: () => void;
} ) {
	const [ missing, setMissing ] = useState( false );
	const pick = ( gallery: GalleryRow ) => {
		if ( typeof gallery.url !== 'string' || ! isGalleryUrl( gallery.url ) ) {
			setMissing( true );
			return;
		}
		setMissing( false );
		setAttributes( { gallery: gallery.url.trim() } );
		onDone?.();
	};
	return (
		<>
			{ missing && (
				<Notice status="warning" isDismissible={ false }>
					{ __(
						'This gallery has no share link yet. Choose another gallery.',
						'profotograaf'
					) }
				</Notice>
			) }
			<Picker onPick={ pick } />
		</>
	);
}

/**
 * The Gallery panel: the chosen share link, and the way to choose or remove
 * one. The block links to the gallery in the same tab.
 */
export function GalleryPanel( { attributes, setAttributes }: PanelProps ) {
	const [ choosing, setChoosing ] = useState( false );
	const chosen = isGalleryUrl( attributes.gallery );
	const empty =
		attributes.linkTo === 'gallery'
			? __( 'No gallery chosen yet.', 'profotograaf' )
			: __( 'No gallery chosen. The block links to the client portal.', 'profotograaf' );
	return (
		<PanelBody
			title={ __( 'Gallery', 'profotograaf' ) }
			initialOpen={ chosen || attributes.linkTo === 'gallery' }
		>
			<p className="profotograaf-client-galleries__chosen">
				{ chosen ? attributes.gallery.trim() : empty }
			</p>
			<Button variant="secondary" onClick={ () => setChoosing( ! choosing ) }>
				{ chosen
					? __( 'Choose another gallery', 'profotograaf' )
					: __( 'Choose a gallery', 'profotograaf' ) }
			</Button>
			{ chosen && (
				<Button variant="tertiary" onClick={ () => setAttributes( { gallery: '' } ) }>
					{ __( 'Remove gallery', 'profotograaf' ) }
				</Button>
			) }
			{ choosing && (
				<GalleryChooser
					setAttributes={ setAttributes }
					onDone={ () => setChoosing( false ) }
				/>
			) }
		</PanelBody>
	);
}
