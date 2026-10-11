import { __ } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { Button, Notice, PanelBody, Placeholder } from '@wordpress/components';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Picker } from '../gallery/picker';
import { ExcludePanel } from '../gallery/exclude-panel';
import { ImageTextPanel } from '../gallery/image-text-panel';
import { clearedAttributes, pickNotice, pickedAttributes } from '../gallery/helpers';
import { usePhotos, type PhotosState } from '../gallery/use-photos';
import type { GalleryRow } from '../gallery/types';
import { DisplayPanel } from './display-panel';
import { withoutPicks } from './picks';
import { SectionsPreview } from './preview';
import { SectionsPanel } from './sections-panel';
import { useSections, type SectionsState } from './use-sections';
import type { FullPageAttributes } from './types';

interface EditProps {
	attributes: FullPageAttributes;
	setAttributes: ( next: Partial< FullPageAttributes > ) => void;
}

/** The Gallery panel: the chosen gallery and the way to choose another. */
function GalleryPanel( { attributes, setAttributes }: EditProps ) {
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Gallery', 'profotograaf' ) }>
				<p>{ attributes.galleryTitle || __( 'No gallery chosen yet.', 'profotograaf' ) }</p>
				{ attributes.galleryId && (
					<Button variant="secondary" onClick={ () => setAttributes( { ...clearedAttributes(), ...withoutPicks( attributes ) } ) }>
						{ __( 'Choose another gallery', 'profotograaf' ) }
					</Button>
				) }
			</PanelBody>
		</InspectorControls>
	);
}

/** The sidebar, in the order of #194: Gallery, Layout, Photos, Display. */
function InspectorPanels( {
	attributes,
	setAttributes,
	photos,
	sections,
}: EditProps & { photos: PhotosState; sections: SectionsState } ) {
	return (
		<>
			<GalleryPanel attributes={ attributes } setAttributes={ setAttributes } />
			{ attributes.galleryId && (
				<>
					<SectionsPanel attributes={ attributes } setAttributes={ setAttributes } answer={ sections.answer } photos={ photos.photos } />
					<ExcludePanel attributes={ attributes } setAttributes={ setAttributes } state={ photos } />
				</>
			) }
			<DisplayPanel attributes={ attributes } setAttributes={ setAttributes } />
			<ImageTextPanel items={ attributes.imageText } setItems={ ( imageText ) => setAttributes( { imageText } ) } />
		</>
	);
}

export default function Edit( { attributes, setAttributes }: EditProps ) {
	const { galleryId } = attributes;
	const [ notice, setNotice ] = useState( '' );
	const photos = usePhotos( galleryId );
	const sections = useSections( attributes );
	const blockProps = useBlockProps();
	const pick = ( gallery: GalleryRow ) => {
		setNotice( '' );
		setAttributes( pickedAttributes( gallery ) );
		void pickNotice( gallery, ( path ) => apiFetch< { available?: boolean } >( { path, method: 'POST' } ) ).then(
			( message ) => {
				if ( message ) {
					setNotice( message );
				}
			}
		);
	};
	return (
		<>
			<InspectorPanels attributes={ attributes } setAttributes={ setAttributes } photos={ photos } sections={ sections } />
			<div { ...blockProps }>
				{ galleryId ? (
					<>
						{ notice && (
							<Notice status="warning" isDismissible={ false }>
								{ notice }
							</Notice>
						) }
						<SectionsPreview state={ sections } />
					</>
				) : (
					<Placeholder
						icon="format-gallery"
						label={ __( 'Full-page gallery', 'profotograaf' ) }
						instructions={ __( 'Choose one of your galleries.', 'profotograaf' ) }
					>
						<Picker onPick={ pick } />
					</Placeholder>
				) }
			</div>
		</>
	);
}
