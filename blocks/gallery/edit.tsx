import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	Button,
	Notice,
	PanelBody,
	Placeholder,
	SelectControl,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Picker } from './picker';
import {
	LIST_PATH,
	clearedAttributes,
	emptyGalleryNotice,
	visibleCountLabel,
	layoutLabel,
	layoutOptions,
	pickedAttributes,
	pickNotice,
} from './helpers';
import { DisplayPanel } from './display-panel';
import { ExcludePanel } from './exclude-panel';
import { ImageTextPanel } from './image-text-panel';
import { PreviewGrid } from './preview-grid';
import { usePhotos, type PhotosState } from './use-photos';
import { useShowable } from './use-showable';
import { layoutHint, platformDefaults } from './layout-map';
import { siteDefaults } from './display-options';
import type { GalleryAttributes, GalleryRow } from './types';

interface EditProps {
	attributes: GalleryAttributes;
	setAttributes: ( next: Partial< GalleryAttributes > ) => void;
}

function Preview( {
	attributes,
	shown,
	notice,
	showable,
}: {
	attributes: GalleryAttributes;
	shown: GalleryRow | null;
	notice: string;
	showable: number | null;
} ) {
	const { galleryTitle, layout } = attributes;
	return (
		<div className="profotograaf-gallery-preview">
			{ shown?.cover_url && (
				<img
					className="profotograaf-gallery-preview__thumb"
					src={ shown.cover_url }
					alt={ shown.cover_alt ?? '' }
				/>
			) }
			<div>
				<p className="profotograaf-gallery-preview__title">
					{ galleryTitle ||
						__( 'Profotograaf gallery', 'profotograaf' ) }
				</p>
				<p className="profotograaf-gallery-preview__line">
					{ shown
						? visibleCountLabel( shown.photo_count, showable ) + ', '
						: '' }
					{ sprintf(
						/* translators: %s: layout name such as grid. */
						__( 'layout: %s', 'profotograaf' ),
						layoutLabel( layout )
					) }
				</p>
				{ notice && (
					<Notice status="warning" isDismissible={ false }>
						{ notice }
					</Notice>
				) }
			</div>
		</div>
	);
}

/**
 * What the Layout control chooses when it is left on the site default: the
 * layout of the gallery on Profotograaf and what the site draws for it. Empty
 * when the block or the site settings pick a layout themselves.
 */
function platformHint( layout: string, row: GalleryRow | null ): string {
	return layout === '' && siteDefaults().layout === '' ? layoutHint( row ) : '';
}

function InspectorPanel( {
	attributes,
	setAttributes,
	listed,
}: EditProps & { listed: GalleryRow | null } ) {
	const { galleryId, layout } = attributes;
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Gallery', 'profotograaf' ) }>
				<SelectControl
					label={ __( 'Layout', 'profotograaf' ) }
					value={ layout }
					options={ layoutOptions() }
					help={ platformHint( layout, listed ) }
					onChange={ ( value ) => setAttributes( { layout: value } ) }
					__nextHasNoMarginBottom
				/>
				{ galleryId && (
					<Button
						variant="secondary"
						onClick={ () => setAttributes( clearedAttributes() ) }
					>
						{ __( 'Choose another gallery', 'profotograaf' ) }
					</Button>
				) }
			</PanelBody>
		</InspectorControls>
	);
}

/**
 * The account's gallery list as it concerns this gallery: its row, and whether
 * the list leaves it out (deleted or unknown).
 */
function useListedGallery( galleryId: string ): { missing: boolean; row: GalleryRow | null } {
	const [ listed, setListed ] = useState< { missing: boolean; row: GalleryRow | null } >( {
		missing: false,
		row: null,
	} );
	useEffect( () => {
		setListed( { missing: false, row: null } );
		if ( ! galleryId ) {
			return undefined;
		}
		const state = { active: true };
		void ( async () => {
			try {
				const rows = await apiFetch< GalleryRow[] >( { path: LIST_PATH } );
				if ( state.active ) {
					const row = rows.find( ( candidate ) => candidate.id === galleryId ) ?? null;
					setListed( { missing: row === null, row } );
				}
			} catch {
				// The picker reports list errors. The notice only needs a clear answer.
			}
		} )();
		return () => {
			state.active = false;
		};
	}, [ galleryId ] );
	return listed;
}

function missingNotice( missing: boolean ): string {
	return missing
		? __(
				'This gallery was not found in your Profotograaf account. It may have been deleted. Choose another gallery.',
				'profotograaf'
		  )
		: '';
}

/** The sidebar panels: layout, display options and, once a gallery is chosen, its photos. */
function InspectorPanels( {
	attributes,
	setAttributes,
	photos,
	listed,
}: EditProps & { photos: PhotosState; listed: GalleryRow | null } ) {
	return (
		<>
			<InspectorPanel attributes={ attributes } setAttributes={ setAttributes } listed={ listed } />
			<DisplayPanel attributes={ attributes } setAttributes={ setAttributes } row={ listed } />
			<ImageTextPanel
				items={ attributes.imageText }
				setItems={ ( imageText ) => setAttributes( { imageText } ) }
			/>
			{ attributes.galleryId && (
				<ExcludePanel
					attributes={ attributes }
					setAttributes={ setAttributes }
					state={ photos }
				/>
			) }
		</>
	);
}

type EmptyNotice = ReturnType< typeof emptyGalleryNotice >;

/** Under the preview card: the empty notice, or a sketch of the gallery. */
function BelowPreview( {
	attributes,
	empty,
	cover,
	row,
	photos,
}: {
	attributes: GalleryAttributes;
	empty: EmptyNotice;
	cover: string | undefined;
	row: GalleryRow | null;
	photos: PhotosState[ 'photos' ];
} ) {
	if ( empty ) {
		return (
			<Notice
				status="warning"
				isDismissible={ false }
				className="profotograaf-gallery-empty-notice"
			>
				<strong>{ empty.message }</strong> { empty.hint }
			</Notice>
		);
	}
	return (
		<PreviewGrid
			attributes={ attributes }
			cover={ cover }
			defaults={ platformDefaults( siteDefaults(), row ) }
			photos={ photos }
			excluded={ attributes.excludedPhotoIds }
			imageText={ attributes.imageText }
		/>
	);
}

export default function Edit( { attributes, setAttributes }: EditProps ) {
	const { galleryId } = attributes;
	const [ notice, setNotice ] = useState( '' );
	const [ preview, setPreview ] = useState< GalleryRow | null >( null );
	const { missing, row: listed } = useListedGallery( galleryId );
	const photos = usePhotos( galleryId );
	const empty = emptyGalleryNotice( useShowable( galleryId ) );
	const blockProps = useBlockProps();
	const pick = ( gallery: GalleryRow ) => {
		setNotice( '' );
		setPreview( gallery );
		setAttributes( pickedAttributes( gallery ) );
		void pickNotice( gallery, ( path ) =>
			apiFetch< { available?: boolean } >( { path, method: 'POST' } )
		).then( ( message ) => {
			if ( message ) {
				setNotice( message );
			}
		} );
	};
	// The preview card uses the cover thumbnail from the picker. It is only known
	// in this editing session, so a reloaded block shows the title alone.
	const shown = preview && preview.id === galleryId ? preview : null;
	return (
		<>
			<InspectorPanels attributes={ attributes } setAttributes={ setAttributes } photos={ photos } listed={ shown ?? listed } />
			<div { ...blockProps }>
				{ ! galleryId ? (
					<Placeholder
						icon="format-gallery"
						label={ __( 'Profotograaf gallery', 'profotograaf' ) }
						instructions={ __(
							'Choose one of your galleries.',
							'profotograaf'
						) }
					>
						<Picker onPick={ pick } />
					</Placeholder>
				) : (
					<Preview
						attributes={ attributes }
						shown={ shown }
						notice={ notice || missingNotice( missing ) }
						showable={ empty ? 0 : null }
					/>
				) }
				{ galleryId && (
					<BelowPreview
						attributes={ attributes }
						empty={ empty }
						cover={ shown?.cover_url } row={ shown ?? listed }
						photos={ photos.photos }
					/>
				) }
			</div>
		</>
	);
}
