import { InspectorControls } from '@wordpress/block-editor';
import { Notice } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { useEffect } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type { ComponentType } from 'react';

/** Body class while an Image block with a virtual attachment is selected. */
export const BODY_CLASS = 'profotograaf-virtual-image-selected';

/** What the REST media response carries for a photo kept on Profotograaf. */
interface VirtualField {
	convert_url?: string;
}

interface MediaRecord {
	profotograaf_virtual?: VirtualField | null;
}

interface CoreSelectors {
	getMedia?: ( id: number, query: { context: string } ) => MediaRecord | undefined | null;
}

export interface BlockEditProps {
	name: string;
	isSelected?: boolean;
	attributes: { id?: unknown };
}

/**
 * The style that hides the Crop button of the selected block while the body
 * class is set. The button has no class of its own, so it is found by its
 * label, which is the translated core string "Crop".
 */
export function cropStyle( label: string ): string {
	const escaped = label.replace( /["\\]/g, '\\$&' );
	return `body.${ BODY_CLASS } .block-editor-block-toolbar button[aria-label="${ escaped }"] { display: none; }`; // i18n-ignore: a CSS selector.
}

/** The kept-photo data of an attachment, or null for a normal one. */
function useVirtual( id: number ): VirtualField | null {
	return useSelect(
		( select ) => {
			if ( id <= 0 ) {
				return null;
			}
			const core = select( 'core' ) as CoreSelectors;
			return core.getMedia?.( id, { context: 'view' } )?.profotograaf_virtual ?? null;
		},
		[ id ]
	);
}

function KeptNotice( { field }: { field: VirtualField } ) {
	return (
		<div className="profotograaf-not-editable">
			<Notice status="warning" isDismissible={ false }>
				<p>
					{ __(
						'This photo is kept on Profotograaf, so this site has no file to edit. Convert it to local to crop, rotate or scale it.',
						'profotograaf'
					) }
				</p>
				{ field.convert_url ? <a href={ field.convert_url }>{ __( 'Convert to local', 'profotograaf' ) }</a> : null }
			</Notice>
		</div>
	);
}

/**
 * Where the notice goes in the block settings. WordPress 7.0 and later show
 * the Image block's media fields in a Content tab, which holds the `content`
 * group. Older versions have one panel, the `default` group. Filling both
 * puts the notice next to the media fields wherever they are; a version
 * without a content group renders nothing for it.
 */
const GROUPS = [ 'content', 'default' ] as const;

/**
 * Wraps the edit component of core/image: for a photo kept on Profotograaf
 * it hides Crop while the block is selected and explains why in the block
 * settings. Every other block renders unchanged.
 */
export function withVirtualImage( BlockEdit: ComponentType< BlockEditProps > ): ComponentType< BlockEditProps > {
	function ImageEdit( props: BlockEditProps ) {
		const id = typeof props.attributes.id === 'number' ? props.attributes.id : 0;
		const field = useVirtual( id );
		const hide = null !== field && true === props.isSelected;
		useEffect( () => {
			if ( ! hide ) {
				return undefined;
			}
			document.body.classList.add( BODY_CLASS );
			return () => document.body.classList.remove( BODY_CLASS );
		}, [ hide ] );
		return (
			<>
				<BlockEdit { ...props } />
				{ field
					? GROUPS.map( ( group ) => (
							<InspectorControls key={ group } group={ group }>
								<KeptNotice field={ field } />
							</InspectorControls>
					  ) )
					: null }
			</>
		);
	}
	return function VirtualImageEdit( props: BlockEditProps ) {
		return 'core/image' === props.name ? <ImageEdit { ...props } /> : <BlockEdit { ...props } />;
	};
}
