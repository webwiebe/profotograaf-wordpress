import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { LIST_PATH, countLabel, errorMessage } from './helpers';
import type { GalleryRow, RestError } from './types';

function PickerRow( {
	gallery,
	onPick,
}: {
	gallery: GalleryRow;
	onPick: ( gallery: GalleryRow ) => void;
} ) {
	return (
		<li className="profotograaf-gallery-picker__item">
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
	);
}

export function Picker( {
	onPick,
}: {
	onPick: ( gallery: GalleryRow ) => void;
} ) {
	const [ galleries, setGalleries ] = useState< GalleryRow[] | null >( null );
	const [ error, setError ] = useState( '' );

	useEffect( () => {
		let active = true;
		apiFetch< GalleryRow[] >( { path: LIST_PATH } )
			.then( ( rows ) => active && setGalleries( rows ) )
			.catch(
				( e: RestError ) => active && setError( errorMessage( e ) )
			);
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
				<PickerRow
					key={ gallery.id }
					gallery={ gallery }
					onPick={ onPick }
				/>
			) ) }
		</ul>
	);
}
