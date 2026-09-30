import { __, _n, sprintf } from '@wordpress/i18n';
import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import {
	Button,
	Notice,
	PanelBody,
	Placeholder,
	SelectControl,
	Spinner,
} from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

const LIST_PATH = '/profotograaf/v1/galleries';

function countLabel( count ) {
	return sprintf(
		/* translators: %d: number of photos in a gallery. */
		_n( '%d photo', '%d photos', count, 'profotograaf' ),
		count
	);
}

function layoutOptions() {
	return [
		{ label: __( 'Site default', 'profotograaf' ), value: '' },
		{ label: __( 'Grid', 'profotograaf' ), value: 'grid' },
		{ label: __( 'Masonry', 'profotograaf' ), value: 'masonry' },
		{ label: __( 'Slideshow', 'profotograaf' ), value: 'slideshow' },
	];
}

function errorMessage( error ) {
	if ( error && error.code === 'profotograaf_not_connected' ) {
		return __(
			'Connect this site to Profotograaf under Settings > Profotograaf to pick a gallery.',
			'profotograaf'
		);
	}
	return (
		( error && error.message ) ||
		__( 'The galleries could not be loaded.', 'profotograaf' )
	);
}

function Picker( { onPick } ) {
	const [ galleries, setGalleries ] = useState( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let active = true;
		apiFetch( { path: LIST_PATH } )
			.then( ( rows ) => active && setGalleries( rows ) )
			.catch( ( e ) => active && setError( errorMessage( e ) ) );
		return () => {
			active = false;
		};
	}, [] );

	if ( error ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ error }
			</Notice>
		);
	}
	if ( galleries === null ) {
		return <Spinner />;
	}
	if ( galleries.length === 0 ) {
		return (
			<p>
				{ __(
					'There are no galleries in your Profotograaf account yet.',
					'profotograaf'
				) }
			</p>
		);
	}

	return (
		<ul className="profotograaf-gallery-picker">
			{ galleries.map( ( gallery ) => (
				<li
					key={ gallery.id }
					className="profotograaf-gallery-picker__item"
				>
					<Button
						className="profotograaf-gallery-picker__button"
						onClick={ () => onPick( gallery ) }
					>
						{ gallery.cover_url ? (
							<img
								className="profotograaf-gallery-picker__thumb"
								src={ gallery.cover_url }
								alt=""
							/>
						) : (
							<span className="profotograaf-gallery-picker__thumb" />
						) }
						<span className="profotograaf-gallery-picker__meta">
							<span className="profotograaf-gallery-picker__title">
								{ gallery.title }
							</span>
							<span className="profotograaf-gallery-picker__count">
								{ countLabel( gallery.photo_count ) }
							</span>
						</span>
					</Button>
				</li>
			) ) }
		</ul>
	);
}

export default function Edit( { attributes, setAttributes } ) {
	const { galleryId, galleryTitle, layout } = attributes;
	const [ notice, setNotice ] = useState( '' );
	const [ preview, setPreview ] = useState( null );
	const blockProps = useBlockProps();

	const pick = ( gallery ) => {
		setNotice( '' );
		setPreview( gallery );
		setAttributes( {
			galleryId: gallery.id,
			galleryTitle: gallery.title,
			galleryUrl: gallery.url,
		} );

		if ( gallery.embeddable && gallery.available ) {
			return;
		}
		if ( gallery.embeddable ) {
			setNotice(
				__(
					'This gallery cannot be shown on other sites right now. A password, an expiry date, proofing mode or a client-only setting stops embedding.',
					'profotograaf'
				)
			);
			return;
		}
		apiFetch( {
			path: `${ LIST_PATH }/${ encodeURIComponent(
				gallery.id
			) }/embeddable`,
			method: 'POST',
		} ).catch( ( e ) => setNotice( errorMessage( e ) ) );
	};

	// The preview card uses the cover thumbnail from the picker. It is only known
	// in this editing session, so a reloaded block shows the title alone.
	const shown = preview && preview.id === galleryId ? preview : null;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Gallery', 'profotograaf' ) }>
					<SelectControl
						label={ __( 'Layout', 'profotograaf' ) }
						value={ layout }
						options={ layoutOptions() }
						onChange={ ( value ) =>
							setAttributes( { layout: value } )
						}
						__nextHasNoMarginBottom
					/>
					{ galleryId && (
						<Button
							variant="secondary"
							onClick={ () =>
								setAttributes( {
									galleryId: '',
									galleryTitle: '',
									galleryUrl: '',
								} )
							}
						>
							{ __( 'Choose another gallery', 'profotograaf' ) }
						</Button>
					) }
				</PanelBody>
			</InspectorControls>
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
					<div className="profotograaf-gallery-preview">
						{ shown && shown.cover_url && (
							<img
								className="profotograaf-gallery-preview__thumb"
								src={ shown.cover_url }
								alt=""
							/>
						) }
						<div>
							<p className="profotograaf-gallery-preview__title">
								{ galleryTitle ||
									__(
										'Profotograaf gallery',
										'profotograaf'
									) }
							</p>
							<p className="profotograaf-gallery-preview__line">
								{ shown
									? countLabel( shown.photo_count ) + ', '
									: '' }
								{ sprintf(
									/* translators: %s: layout name such as grid. */
									__( 'layout: %s', 'profotograaf' ),
									layoutOptions().find(
										( option ) => option.value === layout
									)?.label
								) }
							</p>
							{ notice && (
								<Notice
									status="warning"
									isDismissible={ false }
								>
									{ notice }
								</Notice>
							) }
						</div>
					</div>
				) }
			</div>
		</>
	);
}
