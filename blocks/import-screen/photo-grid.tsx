import { __ } from '@wordpress/i18n';
import type { PhotoItem } from './types';

interface CardProps {
	photo: PhotoItem;
	attachmentId: number;
	selected: boolean;
	disabled: boolean;
	adminUrl: string;
	onToggle: ( photo: PhotoItem ) => void;
}

function PhotoCard( {
	photo,
	attachmentId,
	selected,
	disabled,
	adminUrl,
	onToggle,
}: CardProps ) {
	const imported = attachmentId > 0;
	const classes = [
		'profotograaf-import__card',
		selected ? 'is-selected' : '',
		imported ? 'is-imported' : '',
	];
	return (
		<li className={ classes.filter( Boolean ).join( ' ' ) }>
			<button
				type="button"
				className="profotograaf-import__pick"
				aria-pressed={ selected }
				disabled={ imported || disabled }
				onClick={ () => onToggle( photo ) }
			>
				<img
					src={ photo.previewUrl }
					alt={ photo.alt !== '' ? photo.alt : photo.title }
					loading="lazy"
				/>
				<span className="profotograaf-import__title">{ photo.title }</span>
			</button>
			{ imported && (
				<span className="profotograaf-import__badge">
					{ __( 'Imported', 'profotograaf' ) }{ ' ' }
					<a href={ `${ adminUrl }post.php?post=${ attachmentId }&action=edit` }>
						{ __( 'Open in Media Library', 'profotograaf' ) }
					</a>
				</span>
			) }
		</li>
	);
}

interface GridProps {
	photos: PhotoItem[];
	selected: Record< string, PhotoItem >;
	disabled: boolean;
	adminUrl: string;
	attachmentOf: ( photo: PhotoItem ) => number;
	onToggle: ( photo: PhotoItem ) => void;
}

export function PhotoGrid( { photos, selected, attachmentOf, ...rest }: GridProps ) {
	if ( photos.length === 0 ) {
		return <p>{ __( 'No photos found.', 'profotograaf' ) }</p>;
	}
	return (
		<ul className="profotograaf-import__grid">
			{ photos.map( ( photo ) => (
				<PhotoCard
					key={ photo.sourceId }
					photo={ photo }
					attachmentId={ attachmentOf( photo ) }
					selected={ Boolean( selected[ photo.sourceId ] ) }
					{ ...rest }
				/>
			) ) }
		</ul>
	);
}
