import type { PhotoItem } from '../import-screen/types';
import type {
	AttachmentModel,
	FrameLike,
	MediaGlobal,
	ReadyPhoto,
	SelectionCollection,
	SelectionPort,
} from './types';

const PREFIX = 'profotograaf:';

/** The selection collection of the frame's current state, when it has one. */
function selectionOf( frame: FrameLike ): SelectionCollection | null {
	const selection = frame.state().get( 'selection' ) as SelectionCollection | undefined;
	return selection && typeof selection.add === 'function' ? selection : null;
}

/** A stand-in attachment that makes the frame's own buttons count a picked photo. */
function standIn( media: MediaGlobal, photo: PhotoItem ): AttachmentModel {
	const size = { url: photo.previewUrl, width: 400, height: 400, orientation: 'landscape' };
	return new media.model.Attachment( {
		id: PREFIX + photo.sourceId,
		type: 'image',
		subtype: 'jpeg',
		mime: 'image/jpeg',
		title: photo.title,
		alt: photo.alt,
		url: photo.previewUrl,
		sizes: { thumbnail: size, full: size },
	} );
}

async function load( media: MediaGlobal, ready: ReadyPhoto ): Promise< AttachmentModel | null > {
	const model = media.attachment( ready.attachmentId );
	try {
		await model.fetch?.();
		return model;
	} catch {
		return null;
	}
}

/**
 * Connects the session to the selection of a media frame. Picked photos sit in
 * that selection as stand-ins, so the toolbar buttons enable and the counts
 * match. Committing swaps them for the real, fully loaded attachments.
 */
export function createSelectionPort( frame: FrameLike, media: MediaGlobal ): SelectionPort {
	const standIns = new Map< string, AttachmentModel >();
	let holder: SelectionCollection | null = null;

	const clear = (): void => {
		if ( holder && standIns.size > 0 ) {
			holder.remove( [ ...standIns.values() ] );
		}
		standIns.clear();
	};

	return {
		multiple: () => !! selectionOf( frame )?.multiple,

		show: ( photos ) => {
			const next = new Map( photos.map( ( photo ) => [ photo.sourceId, standIns.get( photo.sourceId ) ?? standIn( media, photo ) ] ) );
			const dropped = [ ...standIns ].filter( ( [ id ] ) => ! next.has( id ) ).map( ( [ , model ] ) => model );
			holder?.remove( dropped );
			holder = selectionOf( frame );
			standIns.clear();
			next.forEach( ( model, id ) => standIns.set( id, model ) );
			holder?.add( [ ...next.values() ] );
		},

		hide: clear,

		commit: async ( ready ) => {
			const loaded = await Promise.all( ready.map( ( item ) => load( media, item ) ) );
			const models: AttachmentModel[] = [];
			const failed: string[] = [];
			ready.forEach( ( item, index ) => {
				const model = loaded[ index ];
				if ( model ) {
					models.push( model );
					holder?.remove( standIns.get( item.photo.sourceId ) );
					standIns.delete( item.photo.sourceId );
				} else {
					failed.push( item.photo.sourceId );
				}
			} );
			holder?.add( models );
			return failed;
		},
	};
}
