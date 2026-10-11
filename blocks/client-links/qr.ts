import qrcode from 'qrcode-generator';

const SVG_NS = 'http://www.w3.org/2000/svg';

/** Light modules around the code, as the QR specification asks. */
const QUIET_ZONE = 4;

/**
 * The dark modules of a QR code for a text, as rows of booleans. Error
 * correction level M, in the smallest symbol that fits. The encoder runs here
 * in the browser, so the link goes to no other service.
 */
export function qrModules( text: string ): boolean[][] {
	const code = qrcode( 0, 'M' );
	code.addData( text );
	code.make();
	const size = code.getModuleCount();
	const rows: boolean[][] = [];
	for ( let row = 0; row < size; row++ ) {
		const cells: boolean[] = [];
		for ( let col = 0; col < size; col++ ) {
			cells.push( code.isDark( row, col ) );
		}
		rows.push( cells );
	}
	return rows;
}

/** SVG path data with one unit square per dark module, offset by the quiet zone. */
export function modulesPath( modules: boolean[][] ): string {
	const parts: string[] = [];
	modules.forEach( ( cells, row ) => {
		cells.forEach( ( dark, col ) => {
			if ( dark ) {
				parts.push( `M${ col + QUIET_ZONE } ${ row + QUIET_ZONE }h1v1h-1z` );
			}
		} );
	} );
	return parts.join( '' );
}

/**
 * An SVG image of the QR code for a text, labelled for screen readers. It
 * scales with its container and stays black on white in every admin color
 * scheme, so a phone camera can read it.
 */
export function qrSvg( doc: Document, text: string, label: string ): SVGSVGElement {
	const modules = qrModules( text );
	const size = modules.length + QUIET_ZONE * 2;
	const svg = doc.createElementNS( SVG_NS, 'svg' );
	svg.setAttribute( 'viewBox', `0 0 ${ size } ${ size }` );
	svg.setAttribute( 'role', 'img' );
	svg.setAttribute( 'aria-label', label );
	svg.setAttribute( 'shape-rendering', 'crispEdges' );

	const background = doc.createElementNS( SVG_NS, 'rect' );
	background.setAttribute( 'width', String( size ) );
	background.setAttribute( 'height', String( size ) );
	background.setAttribute( 'fill', '#fff' );
	svg.appendChild( background );

	const path = doc.createElementNS( SVG_NS, 'path' );
	path.setAttribute( 'd', modulesPath( modules ) );
	path.setAttribute( 'fill', '#000' );
	svg.appendChild( path );
	return svg;
}
