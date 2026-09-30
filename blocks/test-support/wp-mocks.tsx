// Plain-DOM stand-ins for the WordPress editor packages, so the block
// components render in jsdom. Each one exposes what the component passes it as
// something a test can query or click.
import type { ChangeEvent, ReactNode } from 'react';

interface WithChildren {
	children?: ReactNode;
}

export const Button = ( {
	children,
	onClick,
}: WithChildren & { onClick?: () => void } ) => (
	<button type="button" onClick={ onClick }>
		{ children }
	</button>
);

export const Notice = ( { children, status }: WithChildren & { status?: string } ) => (
	<div role="alert" data-status={ status }>
		{ children }
	</div>
);

export const PanelBody = ( { title, children }: WithChildren & { title: string } ) => (
	<section aria-label={ title }>{ children }</section>
);

export const Placeholder = ( {
	label,
	instructions,
	children,
}: WithChildren & { label: string; instructions: string } ) => (
	<div data-placeholder={ label }>
		<p>{ instructions }</p>
		{ children }
	</div>
);

export const Spinner = () => <span data-testid="spinner" />;

interface FieldProps {
	label: string;
	value: string;
	onChange: ( value: string ) => void;
}

export const TextControl = ( { label, value, onChange }: FieldProps ) => (
	<input
		aria-label={ label }
		value={ value }
		onChange={ ( e: ChangeEvent< HTMLInputElement > ) => onChange( e.target.value ) }
	/>
);

export const TextareaControl = ( { label, value, onChange }: FieldProps ) => (
	<textarea
		aria-label={ label }
		value={ value }
		onChange={ ( e: ChangeEvent< HTMLTextAreaElement > ) => onChange( e.target.value ) }
	/>
);

export const ToggleControl = ( {
	label,
	checked,
	onChange,
}: {
	label: string;
	checked: boolean;
	onChange: ( value: boolean ) => void;
} ) => (
	<input
		type="checkbox"
		aria-label={ label }
		checked={ checked }
		onChange={ ( e: ChangeEvent< HTMLInputElement > ) => onChange( e.target.checked ) }
	/>
);

export const SelectControl = ( {
	label,
	value,
	options,
	onChange,
}: {
	label: string;
	value: string;
	options: { label: string; value: string }[];
	onChange: ( value: string ) => void;
} ) => (
	<select
		aria-label={ label }
		value={ value }
		onChange={ ( e: ChangeEvent< HTMLSelectElement > ) => onChange( e.target.value ) }
	>
		{ options.map( ( option ) => (
			<option key={ option.value } value={ option.value }>
				{ option.label }
			</option>
		) ) }
	</select>
);

export const InspectorControls = ( { children }: WithChildren ) => (
	<aside data-testid="inspector">{ children }</aside>
);

export const useBlockProps = () => ( { className: 'wp-block-mock' } );
