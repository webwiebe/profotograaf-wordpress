import { createRoot } from '@wordpress/element';
import { App } from './app';
import './style.css';

const root = document.getElementById( 'profotograaf-import-root' );
if ( root ) {
	createRoot( root ).render(
		<App adminUrl={ root.getAttribute( 'data-admin-url' ) ?? '' } />
	);
}
