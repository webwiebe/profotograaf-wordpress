/** Where and for which gallery the view beacon goes. */
export interface BeaconTarget {
	url: string;
	gallery: string;
}

/** Browser fields that carry a tracking opt out. */
interface PrivacyNavigator extends Navigator {
	globalPrivacyControl?: boolean;
}

/** Whether the visitor opted out through Do Not Track or Global Privacy Control. */
function trackingDisabled(): boolean {
	const nav: PrivacyNavigator = navigator;
	const win: Window & { doNotTrack?: string } = window;
	return nav.doNotTrack === '1' || win.doNotTrack === '1' || nav.globalPrivacyControl === true;
}

/**
 * Send the view beacon once the gallery is visible. Does nothing under Do Not
 * Track or Global Privacy Control.
 *
 * @param element The gallery root, watched for visibility.
 * @param target  Beacon endpoint and gallery id.
 */
export function watchView( element: HTMLElement, target: BeaconTarget | undefined ): void {
	if ( ! target || ! target.url || trackingDisabled() ) {
		return;
	}
	if ( typeof IntersectionObserver === 'undefined' ) {
		sendView( target );
		return;
	}
	let sent = false;
	const observer = new IntersectionObserver( ( entries ) => {
		if ( ! sent && entries.some( ( entry ) => entry.isIntersecting ) ) {
			sent = true;
			observer.disconnect();
			sendView( target );
		}
	}, { threshold: 0.25 } );
	observer.observe( element );
}

/**
 * POST the view as a form body, with sendBeacon first and fetch as fallback.
 *
 * @param target Beacon endpoint and gallery id.
 */
function sendView( target: BeaconTarget ): void {
	const body = new URLSearchParams( {
		gallery: target.gallery,
		source: 'embed',
		origin: location.origin,
	} ).toString();
	const type = 'application/x-www-form-urlencoded';
	if ( typeof navigator.sendBeacon === 'function' && navigator.sendBeacon( target.url, new Blob( [ body ], { type } ) ) ) {
		return;
	}
	void fetch( target.url, {
		method: 'POST',
		body,
		headers: { 'Content-Type': type },
		keepalive: true,
		mode: 'no-cors',
	} ).catch( () => undefined );
}
