/**
 * Builder entry point.
 */
import { createRoot } from '@wordpress/element';
import App from './app';
import './builder.scss';

const mount = document.getElementById( 'mappulse-builder' );
if ( mount ) {
	createRoot( mount ).render(
		<App settings={ window.mappulseBuilder || {} } />
	);
}
