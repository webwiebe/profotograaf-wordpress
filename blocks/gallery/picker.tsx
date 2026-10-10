import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { LIST_PATH, canRetry, errorMessage, visibleCountLabel } from './helpers';
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
						alt={ gallery.cover_alt ?? '' }
					/>
				) : (
					<span className="profotograaf-gallery-picker__thumb" />
				) }
				<span className="profotograaf-gallery-picker__meta">
					<span className="profotograaf-gallery-picker__title">
						{ gallery.title }
					</span>
					<span className="profotograaf-gallery-picker__count">
						{ visibleCountLabel( gallery.photo_count ) }
					</span>
				</span>
			</Button>
		</li>
	);
}

function PickerError( {
	error,
	onRetry,
}: {
	error: RestError;
	onRetry: () => void;
} ) {
	return (
		<>
			<Notice status="warning" isDismissible={ false }>
				{ errorMessage( error ) }
			</Notice>
			{ canRetry( error ) && (
				<Button variant="secondary" onClick={ onRetry }>
					{ __( 'Try again', 'profotograaf' ) }
				</Button>
			) }
		</>
	);
}

export function Picker( {
	onPick,
}: {
	onPick: ( gallery: GalleryRow ) => void;
} ) {
	const [ galleries, setGalleries ] = useState< GalleryRow[] | null >( null );
	const [ error, setError ] = useState< RestError | null >( null );
	const [ attempt, setAttempt ] = useState( 0 );

	useEffect( () => {
		let active = true;
		apiFetch< GalleryRow[] >( { path: LIST_PATH } )
			.then( ( rows ) => active && setGalleries( rows ) )
			.catch( ( e: RestError ) => active && setError( e ) );
		return () => {
			active = false;
		};
	}, [ attempt ] );

	const retry = () => {
		setError( null );
		setGalleries( null );
		setAttempt( ( n ) => n + 1 );
	};

	if ( error ) {
		return <PickerError error={ error } onRetry={ retry } />;
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
