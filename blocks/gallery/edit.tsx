import { __, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	Button,
	Notice,
	PanelBody,
	Placeholder,
	SelectControl,
} from '@wordpress/components';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Picker } from './picker';
import {
	clearedAttributes,
	countLabel,
	layoutLabel,
	layoutOptions,
	pickedAttributes,
	pickNotice,
} from './helpers';
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

export default function Edit( { attributes, setAttributes }: EditProps ) {
	const { galleryId } = attributes;
	const [ notice, setNotice ] = useState( '' );
	const [ preview, setPreview ] = useState< GalleryRow | null >( null );
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
			<InspectorPanel
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
						notice={ notice }
					/>
				) }
			</div>
		</>
	);
}
