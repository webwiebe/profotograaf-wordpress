import { __, _n, sprintf } from '@wordpress/i18n';
import { Button, SelectControl, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import type { GalleryFacet } from './types';

interface FiltersProps {
	galleries: GalleryFacet[];
	gallery: string;
	onSearch: ( search: string ) => void;
	onGallery: ( gallery: string ) => void;
}

export function Filters( { galleries, gallery, onSearch, onGallery }: FiltersProps ) {
	const [ input, setInput ] = useState( '' );
	const options = [
		{ label: __( 'All galleries', 'profotograaf' ), value: '' },
		...galleries.map( ( facet ) => ( {
			label: sprintf(
				/* translators: 1: gallery title, 2: number of photos in the gallery. */
				__( '%1$s (%2$d)', 'profotograaf' ),
				facet.title,
				facet.count
			),
			value: facet.id,
		} ) ),
	];
	return (
		<form
			className="profotograaf-import__filters"
			role="search"
			onSubmit={ ( event ) => {
				event.preventDefault();
				onSearch( input.trim() );
			} }
		>
			<TextControl
				label={ __( 'Search photos', 'profotograaf' ) }
				value={ input }
				onChange={ setInput }
			/>
			<Button type="submit" variant="secondary">
				{ __( 'Search', 'profotograaf' ) }
			</Button>
			<SelectControl
				label={ __( 'Gallery', 'profotograaf' ) }
				value={ gallery }
				options={ options }
				onChange={ onGallery }
			/>
		</form>
	);
}

export function Pager( {
	page,
	totalPages,
	onPage,
}: {
	page: number;
	totalPages: number;
	onPage: ( page: number ) => void;
} ) {
	if ( totalPages <= 1 ) {
		return null;
	}
	return (
		<span className="profotograaf-import__pages">
			<Button variant="secondary" disabled={ page <= 1 } onClick={ () => onPage( page - 1 ) }>
				{ __( 'Previous page', 'profotograaf' ) }
			</Button>
			<span>
				{ sprintf(
					/* translators: 1: current page number, 2: number of pages. */
					__( 'Page %1$d of %2$d', 'profotograaf' ),
					page,
					totalPages
				) }
			</span>
			<Button
				variant="secondary"
				disabled={ page >= totalPages }
				onClick={ () => onPage( page + 1 ) }
			>
				{ __( 'Next page', 'profotograaf' ) }
			</Button>
		</span>
	);
}

interface ActionsProps {
	count: number;
	busy: boolean;
	canSelectPage: boolean;
	onImport: () => void;
	onSelectPage: () => void;
	onClear: () => void;
}

export function Actions( {
	count,
	busy,
	canSelectPage,
	onImport,
	onSelectPage,
	onClear,
}: ActionsProps ) {
	return (
		<>
			<Button variant="primary" disabled={ count === 0 || busy } onClick={ onImport }>
				{ sprintf(
					/* translators: %d: number of selected photos. */
					_n( 'Import %d photo', 'Import %d photos', count, 'profotograaf' ),
					count
				) }
			</Button>
			<Button variant="secondary" disabled={ ! canSelectPage || busy } onClick={ onSelectPage }>
				{ __( 'Select all on this page', 'profotograaf' ) }
			</Button>
			<Button variant="tertiary" disabled={ count === 0 || busy } onClick={ onClear }>
				{ __( 'Clear selection', 'profotograaf' ) }
			</Button>
		</>
	);
}
