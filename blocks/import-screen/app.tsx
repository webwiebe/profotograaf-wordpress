import { __ } from '@wordpress/i18n';
import { Button, Notice, Spinner } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { errorText } from './api';
import { Actions, Filters, Pager } from './controls';
import { PhotoGrid } from './photo-grid';
import { ProgressBar, Results } from './results';
import { useImport } from './use-import';
import { usePhotoList } from './use-photo-list';
import type { PhotoList } from './use-photo-list';

function ListStatus( { list }: { list: PhotoList } ) {
	if ( list.error ) {
		return (
			<>
				<Notice status="error" isDismissible={ false }>
					{ errorText( list.error ) }
				</Notice>
				<Button variant="secondary" onClick={ list.retry }>
					{ __( 'Try again', 'profotograaf' ) }
				</Button>
			</>
		);
	}
	if ( ! list.data ) {
		return <Spinner />;
	}
	return list.data.stale ? (
		<Notice status="warning" isDismissible={ false }>
			{ __( 'Profotograaf could not be reached, so this list may be out of date.', 'profotograaf' ) }
		</Notice>
	) : null;
}

/** The Import from Profotograaf screen. */
export function App( { adminUrl }: { adminUrl: string } ) {
	const [ query, setQuery ] = useState( { search: '', gallery: '', page: 1 } );
	const list = usePhotoList( query );
	const job = useImport();
	const photos = list.data?.items ?? [];
	const chosen = Object.keys( job.selected ).length;

	return (
		<div className="profotograaf-import">
			<Filters
				galleries={ list.galleries }
				gallery={ query.gallery }
				onSearch={ ( search ) => setQuery( { ...query, search, page: 1 } ) }
				onGallery={ ( gallery ) => setQuery( { ...query, gallery, page: 1 } ) }
			/>
			<ListStatus list={ list } />
			{ list.data && (
				<PhotoGrid
					photos={ photos }
					selected={ job.selected }
					disabled={ job.progress !== null }
					adminUrl={ adminUrl }
					attachmentOf={ job.attachmentOf }
					onToggle={ job.toggle }
				/>
			) }
			<div className="profotograaf-import__bar">
				<Actions
					count={ chosen }
					busy={ job.progress !== null }
					canSelectPage={ photos.length > 0 }
					onImport={ () => void job.start() }
					onSelectPage={ () => job.select( photos.filter( ( photo ) => job.attachmentOf( photo ) === 0 ) ) }
					onClear={ job.clear }
				/>
				<Pager
					page={ query.page }
					totalPages={ list.data?.totalPages ?? 0 }
					onPage={ ( page ) => setQuery( { ...query, page } ) }
				/>
			</div>
			{ job.progress && <ProgressBar progress={ job.progress } /> }
			<Results summary={ job.summary } failures={ job.failures } />
		</div>
	);
}
