import { SelectControl } from '@wordpress/components';
import type { ComponentProps } from 'react';
import { sortChoices, type Choice } from './sort-choices';

type SelectProps = Omit< ComponentProps< typeof SelectControl >, 'options' >;

type Props = SelectProps & {
	options: readonly Choice[];
	/**
	 * Set for a list with a natural order, and name that order in a few words
	 * ("sizes, small to large"). The options then keep the order given.
	 */
	naturalOrder?: string;
};

/**
 * The only select of the block editor. Sorts its options alphabetically unless
 * `naturalOrder` names the order they keep; scripts/selects-sorted.test.mjs
 * fails when another file imports SelectControl.
 */
export function SortedSelect( { options, naturalOrder, ...rest }: Props ) {
	const shown = naturalOrder ? [ ...options ] : sortChoices( options );
	return <SelectControl { ...rest } options={ shown } />;
}
