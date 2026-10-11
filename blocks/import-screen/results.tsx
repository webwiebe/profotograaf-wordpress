import { __, sprintf } from '@wordpress/i18n';
import { Notice } from '@wordpress/components';
import type { Progress } from './use-import';
import type { AddMode, ImportFailure } from './types';

export function ProgressBar( { progress }: { progress: Progress } ) {
	return (
		<div className="profotograaf-import__progress" role="status">
			<progress value={ progress.done } max={ progress.total } />
			<span>
				{ progress.mode === 'keep'
					? sprintf(
							/* translators: 1: photos handled so far, 2: photos to keep on Profotograaf. */
							__( 'Keeping %1$d of %2$d on Profotograaf', 'profotograaf' ),
							progress.done,
							progress.total
					  )
					: sprintf(
							/* translators: 1: photos handled so far, 2: photos to import. */
							__( 'Importing %1$d of %2$d', 'profotograaf' ),
							progress.done,
							progress.total
					  ) }
			</span>
		</div>
	);
}

export function Results( {
	summary,
	failures,
	mode = 'import',
}: {
	summary: string;
	failures: ImportFailure[];
	mode?: AddMode;
} ) {
	return (
		<>
			{ summary !== '' && (
				<Notice status="success" isDismissible={ false }>
					{ summary }
				</Notice>
			) }
			{ failures.length > 0 && (
				<Notice status="error" isDismissible={ false }>
					<p>
						{ mode === 'keep'
							? __( 'These photos could not be kept on Profotograaf:', 'profotograaf' )
							: __( 'These photos could not be imported:', 'profotograaf' ) }
					</p>
					<ul className="profotograaf-import__failures">
						{ failures.map( ( failure ) => (
							<li key={ failure.id }>
								{ failure.title }: { failure.message }
							</li>
						) ) }
					</ul>
				</Notice>
			) }
		</>
	);
}
