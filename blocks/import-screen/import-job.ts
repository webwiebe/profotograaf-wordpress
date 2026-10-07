import { BATCH_SIZE, chunk, errorText, importPhotos } from './api';
import type { ImportFailure, ImportResult, PhotoItem, RestError } from './types';

export interface ImportOutcome {
	/** Attachment id by photo id. */
	added: Record< string, number >;
	failed: ImportFailure[];
}

function sort(
	results: ImportResult[],
	titles: Map< string, string >,
	outcome: ImportOutcome
): void {
	for ( const result of results ) {
		if ( result.attachment_id ) {
			outcome.added[ result.id ] = result.attachment_id;
		} else {
			outcome.failed.push( {
				id: result.id,
				title: titles.get( result.id ) ?? result.id,
				message: result.error ?? errorText( null ),
			} );
		}
	}
}

/**
 * Imports the photos in batches so the screen can show progress. A request
 * that fails as a whole fails every photo of its batch with the server's
 * message.
 */
export async function runImport(
	queue: PhotoItem[],
	onProgress: ( done: number ) => void
): Promise< ImportOutcome > {
	const titles = new Map( queue.map( ( photo ) => [ photo.sourceId, photo.title ] ) );
	const outcome: ImportOutcome = { added: {}, failed: [] };
	let done = 0;
	for ( const batch of chunk( queue, BATCH_SIZE ) ) {
		const ids = batch.map( ( photo ) => photo.sourceId );
		try {
			sort( ( await importPhotos( ids ) ).results, titles, outcome );
		} catch ( error ) {
			sort(
				ids.map( ( id ) => ( { id, error: errorText( error as RestError ) } ) ),
				titles,
				outcome
			);
		}
		done += batch.length;
		onProgress( done );
	}
	return outcome;
}
