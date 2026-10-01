import { __ } from '@wordpress/i18n';
import { InspectorControls } from '@wordpress/block-editor';
import { Button, Notice, PanelBody, Spinner } from '@wordpress/components';
import { errorMessage } from './helpers';
import {
	excludedCount,
	excludedLabel,
	isExcluded,
	toggleExcluded,
	type ExcludeAttributes,
} from './exclude';
import type { PhotosState } from './use-photos';
import type { PhotoRow } from './types';

interface PanelProps {
	attributes: ExcludeAttributes;
	setAttributes: ( next: Partial< ExcludeAttributes > ) => void;
	state: PhotosState;
}

function PhotoToggle( {
	photo,
	left,
	onToggle,
}: {
	photo: PhotoRow;
	left: boolean;
	onToggle: () => void;
} ) {
	const name = photo.title || photo.alt || photo.id;
	return (
		<li className="profotograaf-photo-grid__item">
			<button
				type="button"
				className="profotograaf-photo-grid__button"
				aria-pressed={ left }
				aria-label={ name }
				data-excluded={ left ? 'true' : undefined }
				onClick={ onToggle }
			>
				{ photo.thumb_url ? (
					<img src={ photo.thumb_url } alt="" />
				) : (
					<span className="profotograaf-photo-grid__blank" />
				) }
			</button>
		</li>
	);
}

function PhotoGrid( {
	attributes,
	setAttributes,
	photos,
}: Omit< PanelProps, 'state' > & { photos: PhotoRow[] } ) {
	const excluded = attributes.excludedPhotoIds;
	const count = excludedCount( photos, excluded );
	return (
		<>
			<p className="profotograaf-photo-grid__help">
				{ __(
					'Select a photo to leave it out of this gallery block.',
					'profotograaf'
				) }
			</p>
			<ul className="profotograaf-photo-grid">
				{ photos.map( ( photo ) => (
					<PhotoToggle
						key={ photo.id }
						photo={ photo }
						left={ isExcluded( excluded, photo.id ) }
						onToggle={ () =>
							setAttributes( {
								excludedPhotoIds: toggleExcluded( excluded, photo.id ),
							} )
						}
					/>
				) ) }
			</ul>
			{ excluded.length > 0 && (
				<>
					<p className="profotograaf-photo-grid__help">{ excludedLabel( count ) }</p>
					<Button
						variant="secondary"
						onClick={ () => setAttributes( { excludedPhotoIds: [] } ) }
					>
						{ __( 'Show all photos', 'profotograaf' ) }
					</Button>
				</>
			) }
		</>
	);
}

/** Inspector panel with one toggle per photo of the gallery. */
export function ExcludePanel( { attributes, setAttributes, state }: PanelProps ) {
	let body;
	if ( state.error ) {
		body = (
			<Notice status="warning" isDismissible={ false }>
				{ errorMessage( state.error ) }
			</Notice>
		);
	} else if ( state.photos === null ) {
		body = <Spinner />;
	} else if ( state.photos.length === 0 ) {
		body = <p>{ __( 'This gallery has no photos yet.', 'profotograaf' ) }</p>;
	} else {
		body = (
			<PhotoGrid
				attributes={ attributes }
				setAttributes={ setAttributes }
				photos={ state.photos }
			/>
		);
	}
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Photos', 'profotograaf' ) } initialOpen={ false }>
				{ body }
			</PanelBody>
		</InspectorControls>
	);
}
