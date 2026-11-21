import { SelectControl } from '@wordpress/components';
import { sortChoices, type Choice } from './sort-choices';

type Props< V extends string > = {
	label: string;
	value: V;
	options: readonly Choice< V >[];
	onChange: ( value: V ) => void;
	help?: string | undefined;
	__nextHasNoMarginBottom?: boolean | undefined;
	__next40pxDefaultSize?: boolean | undefined;
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
export function SortedSelect< V extends string >( { options, naturalOrder, label, value, onChange, help, __nextHasNoMarginBottom, __next40pxDefaultSize }: Props< V > ) {
	const shown = naturalOrder ? [ ...options ] : sortChoices( options );
	return (
		<SelectControl
			label={ label }
			value={ value }
			options={ shown }
			onChange={ onChange }
			{ ...( help === undefined ? {} : { help } ) }
			{ ...( __nextHasNoMarginBottom ? { __nextHasNoMarginBottom: true as const } : {} ) }
			{ ...( __next40pxDefaultSize ? { __next40pxDefaultSize: true as const } : {} ) }
		/>
	);
}
