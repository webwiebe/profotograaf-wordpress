import { Notice, Spinner } from '@wordpress/components';
import type { CSSProperties } from 'react';
import { emptyGalleryNotice, errorMessage } from '../gallery/helpers';
import { lookData, lookStyle } from './look';
import type { SectionsState } from './use-sections';
import type { PreviewPhoto, PreviewSection } from './types';

/** The types whose photos keep their own shape in the preview. */
const OWN_SHAPE = [ 'masonry', 'feature', 'hero' ];

type Style = Record< string, string >;

/** The custom properties of each type, apart from the gap. */
const TYPE_STYLES: Partial< Record< PreviewSection[ 'type' ], ( section: PreviewSection ) => Style > > = {
	grid: ( section ) => ( {
		'--pf-cols': String( section.columns ?? 3 ),
		'--pf-ratio': section.ratio === 'original' ? 'auto' : ( section.ratio ?? '3-2' ).replace( '-', '/' ),
	} ),
	masonry: ( section ) => ( { '--pf-cols': String( section.columns ?? 3 ) } ),
	hero: ( section ) => ( { '--pf-height': `${ section.height ?? 85 }vh` } ),
	filmstrip: ( section ) => ( { '--pf-row': `${ section.rowHeight ?? 360 }px` } ),
	text: ( section ) => ( { '--pf-align': section.align ?? 'center' } ),
};

/**
 * The custom properties of a section, as Full_Page_Markup writes them, so the
 * block style (style.css, also loaded in the editor) lays the preview out.
 * A hero and a text section have no gap.
 */
export function sectionStyle( section: PreviewSection ): Style {
	const own = { ...TYPE_STYLES[ section.type ]?.( section ), ...lookStyle( section ) };
	return section.type === 'hero' || section.type === 'text' ? own : { '--pf-gap': `${ section.gap ?? 0 }px`, ...own };
}

/** The shape of a photo, width over height. */
function shapeOf( photo: PreviewPhoto ): number {
	return photo.width > 0 && photo.height > 0 ? photo.width / photo.height : 1.5;
}

function Photo( { photo, type }: { photo: PreviewPhoto; type: PreviewSection[ 'type' ] } ) {
	const shape = shapeOf( photo );
	// The thumbnail can be square. Where the front end shows the photo's own
	// shape, the preview crops the thumbnail to it.
	const own: CSSProperties = OWN_SHAPE.includes( type ) ? { aspectRatio: String( shape ), objectFit: 'cover' } : {};
	return (
		<figure className="pf-fp-photo" style={ { '--pf-ar': String( shape ) } as CSSProperties }>
			<span className="pf-fp-open">
				{ photo.thumb ? <img src={ photo.thumb } alt={ photo.alt } style={ own } /> : null }
			</span>
		</figure>
	);
}

function TextSection( { section }: { section: PreviewSection } ) {
	return (
		<section className="pf-fp-s pf-fp-text" data-type="text" style={ sectionStyle( section ) }>
			{ section.heading ? <h2>{ section.heading }</h2> : null }
			{ section.text ? <p>{ section.text }</p> : null }
		</section>
	);
}

function PhotoSection( { section }: { section: PreviewSection } ) {
	const figures = section.photos.map( ( photo, position ) => (
		<Photo key={ `${ photo.id }-${ position }` } photo={ photo } type={ section.type } />
	) );
	return (
		<section
			className={ `pf-fp-s pf-fp-${ section.type }` }
			data-type={ section.type }
			data-side={ section.type === 'pair' ? section.side : undefined }
			style={ sectionStyle( section ) }
			{ ...lookData( section ) }
		>
			{ section.type === 'filmstrip' ? <div className="pf-fp-track">{ figures }</div> : figures }
			{ section.type === 'hero' && section.title ? <h2 className="pf-fp-title">{ section.title }</h2> : null }
		</section>
	);
}

/** A light sketch of the sections the front end draws, with the thumbnails. */
export function SectionsPreview( { state }: { state: SectionsState } ) {
	if ( state.error ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				{ errorMessage( state.error ) }
			</Notice>
		);
	}
	if ( state.answer === null ) {
		return <Spinner />;
	}
	const empty = emptyGalleryNotice( state.answer.sections.length === 0 ? 0 : null );
	if ( empty ) {
		return (
			<Notice status="warning" isDismissible={ false }>
				<strong>{ empty.message }</strong> { empty.hint }
			</Notice>
		);
	}
	return (
		<div className="pf-fp profotograaf-fp-preview" data-preset={ state.answer.preset }>
			{ state.answer.sections.map( ( section, at ) =>
				section.type === 'text' ? (
					<TextSection key={ at } section={ section } />
				) : (
					<PhotoSection key={ at } section={ section } />
				)
			) }
		</div>
	);
}
