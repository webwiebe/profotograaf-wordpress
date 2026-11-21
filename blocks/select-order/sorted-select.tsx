import { SelectControl } from '@wordpress/components';
import { sortChoices, type Choice } from './sort-choices';

type Props = {
	label: string;
	value: string;
	options: readonly Choice[];
	onChange: ( value: string ) => void;
	help?: string | undefined;
	__nextHasNoMarginBottom?: boolean | undefined;
	/**
	 * Set for a list with a natural order, and name that order in a few words
	 * ("sizes, small to large"). The options then keep the order given.
	 */
	naturalOrder?: string | undefined;
};

/**
 * The only select of the block editor. Sorts its options alphabetically unless
 * `naturalOrder` names the order they keep; scripts/selects-sorted.test.mjs
 * fails when another file imports SelectControl.
 */
export function SortedSelect( { options, naturalOrder, label, value, onChange, help, __nextHasNoMarginBottom }: Props ) {
	const shown = naturalOrder ? [ ...options ] : sortChoices( options );
	return <SelectControl label={ label } value={ value } help={ help } options={ shown } onChange={ onChange } __nextHasNoMarginBottom={ __nextHasNoMarginBottom } />;
}
