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
	linkNewTab: string;
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
	linkNewTab: '',
};

interface ChoiceOption {
	label: string;
	value: string;
}

export interface DisplayControl {
	attribute: DisplayKey;
	label: string;
	kind: 'select' | 'number';
	help?: string;
	/** Shown in an empty number control: the value that applies. */
	placeholder?: string;
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

/**
 * The photo shapes. A grid does not draw Original yet (embed.js crops every
 * tile to one shape, wiebe-xyz/professionals#2339), so the option is left out
 * for the grid layout. A block that already holds it keeps the option, marked,
 * so the control still shows the stored value and the author can change it.
 */
function ratioOptions( layout: string, current: string ): ChoiceOption[] {
	const original: ChoiceOption[] = [];
	if ( layout !== 'grid' ) {
		original.push( { label: __( 'Original', 'profotograaf' ), value: 'original' } );
	} else if ( current === 'original' ) {
		original.push( {
			label: __( 'Original (not drawn in a grid yet)', 'profotograaf' ),
			value: 'original',
		} );
	}
	return [
		siteDefault(),
		...original,
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
		{ label: __( 'The photo page on Profotograaf', 'profotograaf' ), value: 'page' },
		{ label: __( 'Your photographer site', 'profotograaf' ), value: 'site' },
		{ label: __( 'The original photo file', 'profotograaf' ), value: 'file' },
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

/** The most columns on a tablet when only the desktop columns are set. */
const TABLET_COLUMNS = 3;

/** The most columns on a phone when only the desktop columns are set. */
const PHONE_COLUMNS: Record< string, number > = { grid: 2, masonry: 1 };
const DEFAULT_PHONE_COLUMNS = 2;

/**
 * The columns on tablets and phones that apply to a block, the way
 * Gallery_Renderer and Reserved_Space work them out: a value set on the block
 * or the site wins; otherwise they step down from the desktop columns (tablet
 * at most 3, phone at most 2 for a grid and 1 for masonry). Empty strings when
 * no columns are set anywhere, because embed.js then picks its own, or for a
 * slideshow.
 */
export function effectiveColumns(
	attributes: Pick< DisplayAttributes, 'columns' | 'columnsTablet' | 'columnsMobile' >,
	defaults: SiteDefaults,
	layout: string
): { tablet: string; mobile: string } {
	const resolved = layout || defaults.layout || 'grid';
	const desktop = firstSet( attributes.columns, defaults.columns );
	const canStep = desktop > 0 && resolved !== 'slideshow';
	const tablet = firstSet( attributes.columnsTablet, defaults.columnsTablet ) || ( canStep ? Math.min( desktop, TABLET_COLUMNS ) : 0 );
	const phoneCap = PHONE_COLUMNS[ resolved ] ?? DEFAULT_PHONE_COLUMNS;
	const mobile = firstSet( attributes.columnsMobile, defaults.columnsMobile ) || ( canStep ? Math.min( tablet, phoneCap ) : 0 );
	return { tablet: tablet ? String( tablet ) : '', mobile: mobile ? String( mobile ) : '' };
}

/** The first of two stored strings that holds a column count, else 0. */
function firstSet( own: string, site: string ): number {
	return wholeColumns( own ) || wholeColumns( site );
}

/** A column count of 1 or more from a stored string, else 0. */
function wholeColumns( value: string ): number {
	return /^\d+$/.test( value ) ? Number( value ) : 0;
}

/** The inspector controls, in display order. */
export function displayControls(
	attributes: Partial< DisplayAttributes > & { layout?: string } = {},
	defaults: SiteDefaults = NO_SITE_DEFAULTS
): DisplayControl[] {
	const shown = effectiveColumns(
		{
			columns: attributes.columns ?? '',
			columnsTablet: attributes.columnsTablet ?? '',
			columnsMobile: attributes.columnsMobile ?? '',
		},
		defaults,
		attributes.layout ?? ''
	);
	const layout = attributes.layout || defaults.layout || 'grid';
	return [
		{ attribute: 'columns', label: __( 'Columns', 'profotograaf' ), kind: 'number', min: 1, max: 8 },
		{ attribute: 'columnsTablet', label: __( 'Columns on tablets', 'profotograaf' ), kind: 'number', min: 1, max: 8, placeholder: shown.tablet },
		{ attribute: 'columnsMobile', label: __( 'Columns on phones', 'profotograaf' ), kind: 'number', min: 1, max: 4, placeholder: shown.mobile },
		{ attribute: 'gap', label: __( 'Gap between photos (pixels)', 'profotograaf' ), kind: 'number', min: 0, max: 96 },
		{ attribute: 'ratio', label: __( 'Photo shape', 'profotograaf' ), kind: 'select', options: ratioOptions( layout, attributes.ratio ?? '' ) },
		{ attribute: 'captions', label: __( 'Captions', 'profotograaf' ), kind: 'select', options: captionOptions() },
		{ attribute: 'sort', label: __( 'Sort order', 'profotograaf' ), kind: 'select', options: sortOptions() },
		{ attribute: 'perPage', label: __( 'Photos per page', 'profotograaf' ), kind: 'number', min: 1, max: 200 },
		{ attribute: 'loadMore', label: __( 'Load more button', 'profotograaf' ), kind: 'select', options: onOff() },
		{ attribute: 'lightbox', label: __( 'Lightbox', 'profotograaf' ), kind: 'select', options: onOff() },
		{
			attribute: 'linkTo',
			label: __( 'Link photos to', 'profotograaf' ),
			kind: 'select',
			options: linkToOptions(),
			help: __( 'Applies only when the lightbox is off. An open lightbox handles the click itself.', 'profotograaf' ),
		},
		{
			attribute: 'linkNewTab',
			label: __( 'Open photo links in a new tab', 'profotograaf' ),
			kind: 'select',
			options: onOff(),
			help: __( 'Applies only when the lightbox is off and photos link somewhere.', 'profotograaf' ),
		},
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
	columnsTablet: string;
	columnsMobile: string;
	gap: string;
	ratio: string;
	layout: string;
};

const NO_SITE_DEFAULTS: SiteDefaults = {
	columns: '',
	columnsTablet: '',
	columnsMobile: '',
	gap: '',
	ratio: '',
	layout: '',
};

/** The site defaults the server passed to the editor, or none. */
export function siteDefaults(): SiteDefaults {
	const passed = ( globalThis as { profotograafGalleryDefaults?: Partial< SiteDefaults > } )
		.profotograafGalleryDefaults;
	const result = { ...NO_SITE_DEFAULTS };
	for ( const key of Object.keys( result ) as Array< keyof SiteDefaults > ) {
		result[ key ] = String( passed?.[ key ] ?? '' );
	}
	return result;
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

/**
 * The link target phrases. embed.js applies the target only while the lightbox
 * is off, so a block that leaves the lightbox to the site default says so.
 */
function linkSummary( attributes: DisplayAttributes ): string[] {
	const linkLabel = linkToOptions().find( ( o ) => o.value === attributes.linkTo )?.label;
	if ( ! attributes.linkTo || ! linkLabel ) {
		return [];
	}
	const target = linkLabel.toLowerCase();
	const parts: string[] = [
		attributes.lightbox === 'off'
			? sprintf(
					/* translators: %s: what a photo links to, such as "the original photo file". */
					__( 'links to: %s', 'profotograaf' ),
					target
			  )
			: sprintf(
					/* translators: %s: what a photo links to, such as "the original photo file". */
					__( 'links to: %s (lightbox off only)', 'profotograaf' ),
					target
			  ),
	];
	if ( attributes.linkNewTab === 'off' ) {
		parts.push( __( 'same tab', 'profotograaf' ) );
	}
	return parts;
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
	if ( attributes.lightbox !== 'on' ) {
		parts.push( ...linkSummary( attributes ) );
	}
	return parts;
}
