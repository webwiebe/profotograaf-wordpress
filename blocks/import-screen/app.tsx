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

/** What keeping a photo on Profotograaf means, shown while the choice is offered. */
function KeepNotice() {
	return (
		<Notice status="info" isDismissible={ false } className="profotograaf-import__keep-notice">
			{ __(
				'Keep on Profotograaf adds a photo to the Media Library without a download. Your site shows it from Profotograaf and stores no copy. If you unpublish its gallery later, the photo stops loading from Profotograaf at once, but copies already held by edge caches and visitors\' browsers can keep showing it for up to a year. Import the photos you may want to withdraw.',
				'profotograaf'
			) }
		</Notice>
	);
}

/**
 * The Import from Profotograaf screen. `canKeep` offers "Keep on Profotograaf"
 * next to Import, while the setting "Keep photos on Profotograaf" is on.
 */
export function App( { adminUrl, canKeep = false }: { adminUrl: string; canKeep?: boolean } ) {
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
			{ canKeep && <KeepNotice /> }
			<ListStatus list={ list } />
			{ list.data && (
				<PhotoGrid
					photos={ photos }
					selected={ job.selected }
					disabled={ job.progress !== null }
					adminUrl={ adminUrl }
					attachmentOf={ job.attachmentOf }
					keptOf={ job.keptOf }
					onToggle={ job.toggle }
				/>
			) }
			<div className="profotograaf-import__bar">
				<Actions
					count={ chosen }
					busy={ job.progress !== null }
					canSelectPage={ photos.length > 0 }
					onImport={ () => void job.start( 'import' ) }
					onKeep={ canKeep ? () => void job.start( 'keep' ) : undefined }
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
			<Results summary={ job.summary } failures={ job.failures } mode={ job.failedMode } />
		</div>
	);
}
