import { __, _n, sprintf } from '@wordpress/i18n';

/**
 * The display options of the block. Every value is a string. An empty string
 * means "use the site default", which the server resolves (see
 * Gallery_Renderer::OPTIONS). To add an option, add it here, to block.json
 * and to Gallery_Renderer::OPTIONS. `excludedPhotoIds` is a list of photo ids
 * and lives in exclude.ts.
 */
export type DisplayAttributes = {
	columns: string;
	columnsTablet: string;
	columnsMobile: string;
	gap: string;
	ratio: string;
	captions: string;
	sort: string;
	perPage: string;
	loadMore: string;
	lightbox: string;
	linkTo: string;
};

type DisplayKey = keyof DisplayAttributes;

export const DISPLAY_DEFAULTS: DisplayAttributes = {
	columns: '',
	columnsTablet: '',
	columnsMobile: '',
	gap: '',
	ratio: '',
	captions: '',
	sort: '',
	perPage: '',
	loadMore: '',
	lightbox: '',
	linkTo: '',
};

interface ChoiceOption {
	label: string;
	value: string;
}

export interface DisplayControl {
	attribute: DisplayKey;
	label: string;
	kind: 'select' | 'number';
	options?: ChoiceOption[];
	min?: number;
	max?: number;
}

function siteDefault(): ChoiceOption {
	return { label: __( 'Site default', 'profotograaf' ), value: '' };
}

function onOff(): ChoiceOption[] {
	return [
		siteDefault(),
		{ label: __( 'On', 'profotograaf' ), value: 'on' },
		{ label: __( 'Off', 'profotograaf' ), value: 'off' },
	];
}

function ratioOptions(): ChoiceOption[] {
	return [
		siteDefault(),
		{ label: __( 'Original', 'profotograaf' ), value: 'original' },
		{ label: __( 'Square (1:1)', 'profotograaf' ), value: '1-1' },
		{ label: '4:3', value: '4-3' },
		{ label: '3:2', value: '3-2' },
		{ label: '16:9', value: '16-9' },
		{ label: '3:4', value: '3-4' },
		{ label: '2:3', value: '2-3' },
	];
}

function captionOptions(): ChoiceOption[] {
	return [
		siteDefault(),
		{ label: __( 'Hidden', 'profotograaf' ), value: 'off' },
		{ label: __( 'Below the photo', 'profotograaf' ), value: 'below' },
		{ label: __( 'On top of the photo', 'profotograaf' ), value: 'overlay' },
	];
}

function linkToOptions(): ChoiceOption[] {
	return [
		siteDefault(),
		{ label: __( 'Nothing', 'profotograaf' ), value: 'none' },
		{ label: __( 'The photo file', 'profotograaf' ), value: 'file' },
		{ label: __( 'The photo page on Profotograaf', 'profotograaf' ), value: 'page' },
	];
}

function sortOptions(): ChoiceOption[] {
	return [
		siteDefault(),
		{ label: __( 'Newest first', 'profotograaf' ), value: 'newest' },
		{ label: __( 'Oldest first', 'profotograaf' ), value: 'oldest' },
		{ label: __( 'By file name', 'profotograaf' ), value: 'name' },
		{ label: __( 'Random', 'profotograaf' ), value: 'random' },
	];
}

/** The inspector controls, in display order. */
export function displayControls(): DisplayControl[] {
	return [
		{ attribute: 'columns', label: __( 'Columns', 'profotograaf' ), kind: 'number', min: 1, max: 8 },
		{ attribute: 'columnsTablet', label: __( 'Columns on tablets', 'profotograaf' ), kind: 'number', min: 1, max: 8 },
		{ attribute: 'columnsMobile', label: __( 'Columns on phones', 'profotograaf' ), kind: 'number', min: 1, max: 4 },
		{ attribute: 'gap', label: __( 'Gap between photos (pixels)', 'profotograaf' ), kind: 'number', min: 0, max: 96 },
		{ attribute: 'ratio', label: __( 'Photo shape', 'profotograaf' ), kind: 'select', options: ratioOptions() },
		{ attribute: 'captions', label: __( 'Captions', 'profotograaf' ), kind: 'select', options: captionOptions() },
		{ attribute: 'sort', label: __( 'Sort order', 'profotograaf' ), kind: 'select', options: sortOptions() },
		{ attribute: 'perPage', label: __( 'Photos per page', 'profotograaf' ), kind: 'number', min: 1, max: 200 },
		{ attribute: 'loadMore', label: __( 'Load more button', 'profotograaf' ), kind: 'select', options: onOff() },
		{ attribute: 'lightbox', label: __( 'Lightbox', 'profotograaf' ), kind: 'select', options: onOff() },
		{ attribute: 'linkTo', label: __( 'Link photos to', 'profotograaf' ), kind: 'select', options: linkToOptions() },
	];
}

/** A whole number inside the bounds as a string, or '' for anything else. */
export function cleanNumber( raw: string, min: number, max: number ): string {
	if ( raw.trim() === '' ) {
		return '';
	}
	const number = Math.round( Number( raw ) );
	if ( ! Number.isFinite( number ) ) {
		return '';
	}
	return String( Math.min( max, Math.max( min, number ) ) );
}

/** The CSS aspect-ratio for a stored ratio such as 4-3. */
export function cssRatio( ratio: string ): string {
	return /^\d+-\d+$/.test( ratio ) ? ratio.replace( '-', ' / ' ) : '4 / 3';
}

/**
 * The site defaults for the options the preview draws, as the server resolved
 * them (see Gallery_Embed::editor_defaults). An empty string means the
 * Profotograaf default.
 */
export type SiteDefaults = {
	columns: string;
	gap: string;
	ratio: string;
};

const NO_SITE_DEFAULTS: SiteDefaults = { columns: '', gap: '', ratio: '' };

/** The site defaults the server passed to the editor, or none. */
export function siteDefaults(): SiteDefaults {
	const passed = ( globalThis as { profotograafGalleryDefaults?: Partial< SiteDefaults > } )
		.profotograafGalleryDefaults;
	return {
		columns: String( passed?.columns ?? '' ),
		gap: String( passed?.gap ?? '' ),
		ratio: String( passed?.ratio ?? '' ),
	};
}

/**
 * What the preview draws: the block attribute when set, else the site default,
 * else the Profotograaf default (3 columns, 8 pixels, 4 / 3).
 */
export function previewLayout(
	attributes: Pick< DisplayAttributes, 'columns' | 'gap' | 'ratio' >,
	defaults: SiteDefaults = NO_SITE_DEFAULTS
): { columns: number; gap: number; ratio: string } {
	const pick = ( own: string, site: string ) => ( own !== '' ? own : site );
	const columns = Number( pick( attributes.columns, defaults.columns ) ) || 3;
	const gapValue = pick( attributes.gap, defaults.gap );
	const gap = gapValue === '' || ! Number.isFinite( Number( gapValue ) ) ? 8 : Number( gapValue );
	return { columns, gap, ratio: cssRatio( pick( attributes.ratio, defaults.ratio ) ) };
}

/** Short phrases for the options that are set, for the block preview. */
export function displaySummary( attributes: DisplayAttributes ): string[] {
	const parts: string[] = [];
	const sortLabel = sortOptions().find( ( o ) => o.value === attributes.sort )?.label;
	if ( attributes.sort && sortLabel ) {
		parts.push( sortLabel );
	}
	const perPage = Number( attributes.perPage );
	if ( perPage > 0 ) {
		parts.push(
			sprintf(
				/* translators: %d: number of photos shown at first. */
				_n( '%d photo per page', '%d photos per page', perPage, 'profotograaf' ),
				perPage
			)
		);
	}
	if ( attributes.loadMore === 'on' ) {
		parts.push( __( 'load more button', 'profotograaf' ) );
	}
	if ( attributes.lightbox === 'on' ) {
		parts.push( __( 'lightbox on', 'profotograaf' ) );
	} else if ( attributes.lightbox === 'off' ) {
		parts.push( __( 'lightbox off', 'profotograaf' ) );
	}
	const linkLabel = linkToOptions().find( ( o ) => o.value === attributes.linkTo )?.label;
	if ( attributes.linkTo && linkLabel ) {
		parts.push(
			sprintf(
				/* translators: %s: what a photo links to, such as "The photo file". */
				__( 'links to: %s', 'profotograaf' ),
				linkLabel.toLowerCase()
			)
		);
	}
	return parts;
}
