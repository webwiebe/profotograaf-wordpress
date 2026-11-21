/**
 * Alphabetical order for every select of the plugin (owner rule 2026-11-21).
 *
 * Options sort case-insensitively and by the editor locale ("é" sorts with
 * "e", digits by value). Entries that stand for "no choice" stay first, in
 * the order they came: the site default, All, Any and None. A list with a
 * natural order (sizes, a ladder, numbers) skips the sort through
 * `SortedSelect`'s `naturalOrder`, which names that order.
 */

export interface Choice {
	label: string;
	value: string;
}

/** Values of the entries that stay first. */
export const PINNED_VALUES: readonly string[] = [ '', 'all', 'any', 'none' ];

/** The language of the editor, for the collator. */
function editorLocale(): string | undefined {
	return ( typeof document !== 'undefined' && document.documentElement.lang ) || undefined;
}

/**
 * The options in alphabetical order, the pinned ones first.
 *
 * @param options Options of a select.
 * @param locale  A BCP 47 tag. The editor language when omitted.
 */
export function sortChoices< T extends Choice >( options: readonly T[], locale: string | undefined = editorLocale() ): T[] {
	const collator = new Intl.Collator( locale, { sensitivity: 'base', numeric: true } );
	const pinned = options.filter( ( option ) => PINNED_VALUES.includes( option.value ) );
	const rest = options.filter( ( option ) => ! PINNED_VALUES.includes( option.value ) );
	return [ ...pinned, ...rest.sort( ( a, b ) => collator.compare( a.label, b.label ) ) ];
}
