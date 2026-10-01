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
	countLabel,
	layoutLabel,
	layoutOptions,
	pickedAttributes,
	pickNotice,
} from './helpers';
import { DisplayPanel } from './display-panel';
import { ImageTextPanel } from './image-text-panel';
import { PreviewGrid } from './preview-grid';
import type { GalleryAttributes, GalleryRow } from './types';

interface EditProps {
	attributes: GalleryAttributes;
	setAttributes: ( next: Partial< GalleryAttributes > ) => void;
}

function Preview( {
	attributes,
	shown,
	notice,
}: {
	attributes: GalleryAttributes;
	shown: GalleryRow | null;
	notice: string;
} ) {
	const { galleryTitle, layout } = attributes;
	return (
		<div className="profotograaf-gallery-preview">
			{ shown?.cover_url && (
				<img
					className="profotograaf-gallery-preview__thumb"
					src={ shown.cover_url }
					alt=""
				/>
			) }
			<div>
				<p className="profotograaf-gallery-preview__title">
					{ galleryTitle ||
						__( 'Profotograaf gallery', 'profotograaf' ) }
				</p>
				<p className="profotograaf-gallery-preview__line">
					{ shown ? countLabel( shown.photo_count ) + ', ' : '' }
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

function InspectorPanel( { attributes, setAttributes }: EditProps ) {
	const { galleryId, layout } = attributes;
	return (
		<InspectorControls>
			<PanelBody title={ __( 'Gallery', 'profotograaf' ) }>
				<SelectControl
					label={ __( 'Layout', 'profotograaf' ) }
					value={ layout }
					options={ layoutOptions() }
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

function InspectorPanels( { attributes, setAttributes }: EditProps ) {
	return (
		<>
			<InspectorPanel
				attributes={ attributes }
				setAttributes={ setAttributes }
			/>
			<DisplayPanel
				attributes={ attributes }
				setAttributes={ setAttributes }
			/>
			<ImageTextPanel
				items={ attributes.imageText }
				setItems={ ( imageText ) => setAttributes( { imageText } ) }
			/>
		</>
	);
}

/** Whether the account's gallery list leaves this gallery out (deleted or unknown). */
function useMissingGallery( galleryId: string ): boolean {
	const [ missing, setMissing ] = useState( false );
	useEffect( () => {
		setMissing( false );
		if ( ! galleryId ) {
			return undefined;
		}
		const state = { active: true };
		void ( async () => {
			try {
				const rows = await apiFetch< GalleryRow[] >( { path: LIST_PATH } );
				if ( state.active ) {
					setMissing( ! rows.some( ( row ) => row.id === galleryId ) );
				}
			} catch {
				// The picker reports list errors. The notice only needs a clear answer.
			}
		} )();
		return () => {
			state.active = false;
		};
	}, [ galleryId ] );
	return missing;
}

function missingNotice( missing: boolean ): string {
	return missing
		? __(
				'This gallery was not found in your Profotograaf account. It may have been deleted. Choose another gallery.',
				'profotograaf'
		  )
		: '';
}

export default function Edit( { attributes, setAttributes }: EditProps ) {
	const { galleryId } = attributes;
	const [ notice, setNotice ] = useState( '' );
	const [ preview, setPreview ] = useState< GalleryRow | null >( null );
	const missing = useMissingGallery( galleryId );
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
			<InspectorPanels
				attributes={ attributes }
				setAttributes={ setAttributes }
			/>
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
					/>
				) }
				{ galleryId && (
					<PreviewGrid
						attributes={ attributes }
						cover={ shown?.cover_url }
						imageText={ attributes.imageText }
					/>
				) }
			</div>
		</>
	);
}
