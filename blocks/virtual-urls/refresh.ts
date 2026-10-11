/**
 * Keeps the `url` attribute of Image blocks current for Profotograaf photos,
 * the Image blocks inside a Gallery block included (issue #154).
 *
 * A photo kept on Profotograaf gets a new URL when the sync finds a new image
 * version, and a conversion to local moves it to the uploads folder. The
 * block keeps the URL it was saved with. This module finds Image blocks whose
 * URL is on the platform host, asks the REST field `profotograaf_urls` for
 * the current URLs of their attachments once per attachment, and updates the
 * attributes where they differ. The server works out the URLs, so a photo
 * that is still kept on Profotograaf while the setting is off (no working
 * URL) comes back as null and keeps its saved URL.
 */

/** Current URLs of an attachment, as the REST field returns them. */
export interface AttachmentUrls {
	full: string;
	sizes: Record< string, string >;
}

/** A block as the block editor store returns it. */
export interface EditorBlock {
	clientId: string;
	name: string;
	attributes: Record< string, unknown >;
	innerBlocks?: EditorBlock[];
}

/** An Image block that shows a Profotograaf URL. */
export interface PlatformImage {
	clientId: string;
	id: number;
	url: string;
	sizeSlug: string;
	href: string;
	linkDestination: string;
}

/** An attribute update for one block. */
export interface Change {
	clientId: string;
	attributes: { url: string; href?: string };
}

/** What the refresher needs from the editor and from REST. */
export interface RefresherDeps {
	platformUrl: string;
	getBlocks: () => EditorBlock[];
	update: ( change: Change ) => void;
	fetchUrls: ( ids: number[] ) => Promise< Map< number, AttachmentUrls | null > >;
}

/** Attachments asked per REST request, the most the media route returns per page. */
export const BATCH = 100;

const text = ( value: unknown ): string => ( typeof value === 'string' ? value : '' );

/** Host of a URL in lower case, or empty for anything that is not http(s). */
function hostOf( url: string ): string {
	try {
		const parsed = new URL( url );
		return [ 'http:', 'https:' ].includes( parsed.protocol ) ? parsed.host.toLowerCase() : '';
	} catch {
		return '';
	}
}

/** Whether a URL is on the platform host. */
export function isPlatformUrl( url: string, platformUrl: string ): boolean {
	const host = hostOf( url );
	return '' !== host && host === hostOf( platformUrl );
}

/** Every Image block with an attachment id and a platform URL, nested ones included. */
export function collectImages( blocks: EditorBlock[], platformUrl: string ): PlatformImage[] {
	const found: PlatformImage[] = [];
	const walk = ( list: EditorBlock[] ): void => {
		for ( const block of list ) {
			const { attributes } = block;
			const id = attributes.id;
			const url = text( attributes.url );
			if ( 'core/image' === block.name && typeof id === 'number' && id > 0 && isPlatformUrl( url, platformUrl ) ) {
				found.push( {
					clientId: block.clientId,
					id,
					url,
					sizeSlug: text( attributes.sizeSlug ) || 'full',
					href: text( attributes.href ),
					linkDestination: text( attributes.linkDestination ),
				} );
			}
			walk( block.innerBlocks ?? [] );
		}
	};
	walk( blocks );
	return found;
}

/** The current URL for a size, `full` when the attachment has no such size. */
export function currentUrl( urls: AttachmentUrls, sizeSlug: string ): string {
	return Object.hasOwn( urls.sizes, sizeSlug ) ? String( urls.sizes[ sizeSlug ] ) : urls.full;
}

/** The attribute updates for the images whose attachment URLs are known. */
export function changesFor( images: PlatformImage[], known: Map< number, AttachmentUrls | null > ): Change[] {
	const changes: Change[] = [];
	for ( const image of images ) {
		const urls = known.get( image.id );
		if ( ! urls ) {
			continue;
		}
		const url = currentUrl( urls, image.sizeSlug );
		if ( '' === url || url === image.url ) {
			continue;
		}
		const change: Change = { clientId: image.clientId, attributes: { url } };
		// A link to the media file points at the full image.
		if ( 'media' === image.linkDestination && image.href === image.url && '' !== urls.full ) {
			change.attributes.href = urls.full;
		}
		changes.push( change );
	}
	return changes;
}

/** One value of the REST field, or null when it holds no URL. */
function toUrls( field: unknown ): AttachmentUrls | null {
	if ( typeof field !== 'object' || field === null ) {
		return null;
	}
	const value = field as Record< string, unknown >;
	const full = text( value.full );
	if ( '' === full ) {
		return null;
	}
	const sizes: Record< string, string > = {};
	const raw = typeof value.sizes === 'object' && value.sizes !== null ? ( value.sizes as Record< string, unknown > ) : {};
	for ( const [ name, url ] of Object.entries( raw ) ) {
		if ( '' !== text( url ) ) {
			sizes[ name ] = text( url );
		}
	}
	return { full, sizes };
}

/** Reads the REST field from a media collection response. */
export function parseUrls( ids: number[], body: unknown ): Map< number, AttachmentUrls | null > {
	const known = new Map< number, AttachmentUrls | null >( ids.map( ( id ) => [ id, null ] ) );
	for ( const row of Array.isArray( body ) ? body : [] ) {
		if ( typeof row !== 'object' || row === null ) {
			continue;
		}
		const { id, profotograaf_urls: field } = row as Record< string, unknown >;
		if ( typeof id === 'number' && known.has( id ) ) {
			known.set( id, toUrls( field ) );
		}
	}
	return known;
}

/** The REST path that reads the field for some attachments. */
export function mediaPath( ids: number[] ): string {
	return `/wp/v2/media?include=${ ids.join( ',' ) }&per_page=${ BATCH }&_fields=id,profotograaf_urls`;
}

/**
 * Builds the store listener. Each attachment is asked about once per editor
 * session. A failed request leaves its images alone and is not retried, so
 * an outage never makes the editor loop.
 */
export function createRefresher( deps: RefresherDeps ): () => void {
	const known = new Map< number, AttachmentUrls | null >();
	const asked = new Set< number >();
	let lastBlocks: EditorBlock[] | null = null;

	const run = (): void => {
		const blocks = deps.getBlocks();
		if ( blocks === lastBlocks ) {
			return;
		}
		lastBlocks = blocks;
		const images = collectImages( blocks, deps.platformUrl );
		for ( const change of changesFor( images, known ) ) {
			deps.update( change );
		}
		const missing = [ ...new Set( images.map( ( image ) => image.id ) ) ].filter( ( id ) => ! asked.has( id ) );
		for ( let start = 0; start < missing.length; start += BATCH ) {
			const ids = missing.slice( start, start + BATCH );
			ids.forEach( ( id ) => asked.add( id ) );
			deps.fetchUrls( ids ).then(
				( result ) => {
					result.forEach( ( urls, id ) => known.set( id, urls ) );
					lastBlocks = null;
					run();
				},
				( error: unknown ) => {
					// eslint-disable-next-line no-console
					console.warn( 'The current Profotograaf photo URLs could not be loaded.', error );
				}
			);
		}
	};
	return run;
}
