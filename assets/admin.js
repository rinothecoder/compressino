/**
 * Compressino browser compression.
 *
 * Converts PNG and JPG images to WebP on the computer, before upload, with the browser's own
 * encoder (canvas toBlob). It hooks the core uploaders only: every wp.Uploader (media window,
 * Media Library, drops), the Add New Media screen, and REST uploads made through wp.apiFetch.
 * Anything it cannot convert is uploaded as it is, and the server safety net takes over.
 *
 * Other developers can use it for their own uploaders:
 *   window.compressino.attach( pluploadUploader );  // a plupload.Uploader instance
 *   window.compressino.compress( file ).then( ... ); // resolves with a WebP File, or the original
 */
(() => {
	'use strict';
	if ( window.compressino ) {
		return;
	}
	const settings = window.compressinoSettings || {};
	const quality = Math.min( 100, Math.max( 1, Number( settings.quality ) || 90 ) ) / 100;
	const types = [ 'image/png', 'image/jpeg' ];
	const maxPixels = 50000000; // Larger images can crash a browser tab. The server handles those.
	const done = new WeakSet(); // Files already looked at, so nothing is processed twice.
	const originals = new WeakMap(); // Converted file => original file.

	const isAnimatedPng = async ( file ) => {
		const text = new TextDecoder( 'latin1' ).decode( await file.slice( 0, 65536 ).arrayBuffer() );
		const actl = text.indexOf( 'acTL' );
		const idat = text.indexOf( 'IDAT' );
		return actl !== -1 && ( idat === -1 || actl < idat );
	};

	const decode = async ( file ) => {
		if ( window.createImageBitmap ) {
			try {
				return await createImageBitmap( file, { imageOrientation: 'from-image' } );
			} catch ( e ) {}
		}
		const url = URL.createObjectURL( file );
		try {
			const image = new Image();
			image.decoding = 'async';
			image.src = url;
			await image.decode();
			return image;
		} finally {
			URL.revokeObjectURL( url );
		}
	};

	/** Resolve with a smaller WebP File, or with the original file when that is not possible. */
	const compress = async ( file ) => {
		if ( ! file || ! types.includes( file.type ) || originals.has( file ) ) {
			return file;
		}
		try {
			if ( 'image/png' === file.type && await isAnimatedPng( file ) ) {
				return file;
			}
			const source = await decode( file );
			const sourceWidth = source.naturalWidth || source.width;
			const sourceHeight = source.naturalHeight || source.height;
			if ( ! sourceWidth || ! sourceHeight || sourceWidth * sourceHeight > maxPixels ) {
				return file;
			}
			const canvas = document.createElement( 'canvas' );
			canvas.width = sourceWidth;
			canvas.height = sourceHeight;
			canvas.getContext( '2d' ).drawImage( source, 0, 0 );
			if ( source.close ) {
				source.close();
			}
			const blob = await new Promise( ( resolve ) => canvas.toBlob( resolve, 'image/webp', quality ) );
			canvas.width = 0;
			canvas.height = 0;
			// Some browsers cannot encode WebP and return a PNG instead.
			if ( ! blob || 'image/webp' !== blob.type || blob.size >= file.size ) {
				return file;
			}
			const name = file.name.replace( /\.(png|jpe?g)$/i, '' ) + '.webp';
			const webp = new File( [ blob ], name, { type: 'image/webp', lastModified: Date.now() } );
			originals.set( webp, file );
			return webp;
		} catch ( e ) {
			return file;
		}
	};

	/** Hook one plupload instance. Images are taken out of the queue, converted, then added back. */
	const attach = ( uploader ) => {
		if ( ! uploader || ! uploader.bind || uploader.compressino ) {
			return;
		}
		uploader.compressino = true;
		uploader.bind( 'FilesAdded', ( up, files ) => {
			const queue = [];
			for ( let i = files.length - 1; i >= 0; i-- ) {
				const native = files[ i ].getNative ? files[ i ].getNative() : null;
				if ( ! native || done.has( native ) || originals.has( native ) || ! types.includes( native.type ) ) {
					continue;
				}
				done.add( native );
				up.removeFile( files[ i ] );
				files.splice( i, 1 );
				queue.unshift( native );
			}
			queue.reduce( ( chain, native ) => chain.then( () => compress( native ).then( ( result ) => {
				done.add( result );
				up.addFile( result );
			} ) ), Promise.resolve() );
		}, null, 100 );
		// Tell the server the original size, so the savings can be shown.
		uploader.bind( 'BeforeUpload', ( up, file ) => {
			const native = file.getNative ? file.getNative() : null;
			const original = native ? originals.get( native ) : null;
			up.settings.multipart_params = up.settings.multipart_params || {};
			if ( original ) {
				up.settings.multipart_params.compressino_original_size = original.size;
				up.settings.multipart_params.compressino_original_type = original.type;
			} else {
				delete up.settings.multipart_params.compressino_original_size;
				delete up.settings.multipart_params.compressino_original_type;
			}
		}, null, 100 );
	};

	// Every media window, the Media Library and their drop zones use wp.Uploader.
	const hookMediaUploader = () => {
		if ( ! window.wp || ! wp.Uploader || wp.Uploader.prototype.compressino ) {
			return;
		}
		const init = wp.Uploader.prototype.init;
		wp.Uploader.prototype.compressino = true;
		wp.Uploader.prototype.init = function () {
			attach( this.uploader );
			return init.apply( this, arguments );
		};
	};

	// The block editor uploads through the REST API with wp.apiFetch.
	const hookApiFetch = () => {
		if ( ! window.wp || ! wp.apiFetch || wp.apiFetch.compressino ) {
			return;
		}
		wp.apiFetch.compressino = true;
		wp.apiFetch.use( ( options, next ) => {
			const target = options.path || options.url || '';
			const body = options.body;
			const method = ( options.method || 'GET' ).toUpperCase();
			if ( 'POST' !== method || ! /wp\/v2\/media\/?(\?|$)/.test( target ) || ! ( body instanceof FormData ) ) {
				return next( options );
			}
			const file = body.get( 'file' );
			if ( ! ( file instanceof File ) || ! types.includes( file.type ) ) {
				return next( options );
			}
			return compress( file ).then( ( result ) => {
				if ( result === file ) {
					return next( options );
				}
				const data = new FormData();
				body.forEach( ( value, key ) => {
					if ( 'file' === key ) {
						data.append( 'file', result, result.name );
					} else {
						data.append( key, value );
					}
				} );
				data.append( 'compressino_original_size', String( file.size ) );
				data.append( 'compressino_original_type', file.type );
				return next( Object.assign( {}, options, { body: data } ) );
			} );
		} );
	};

	// The classic Add New Media screen creates one global plupload instance.
	const hookAddNewScreen = () => {
		if ( window.uploader && window.plupload && window.uploader instanceof plupload.Uploader ) {
			attach( window.uploader );
		}
	};

	/* ---------- Settings screen: quality slider with a live preview ---------- */

	const size = ( bytes ) => ( bytes >= 1048576 ? ( bytes / 1048576 ).toFixed( 1 ) + ' MB' : Math.max( 1, Math.round( bytes / 1024 ) ) + ' KB' );

	const setupQuality = () => {
		const box = document.querySelector( '.cp-quality' );
		if ( ! box ) {
			return;
		}
		const data = JSON.parse( box.dataset.preview || '{}' );
		const ids = data.ids || [];
		const labels = data.labels || {};
		// The bundled sample photo comes first, then the site's own images. Nothing is added to the Media Library.
		const items = ( data.sample ? [ 0 ] : [] ).concat( ids );
		const range = box.querySelector( '#cp-quality' );
		const output = box.querySelector( '#cp-quality-value' );
		const note = box.querySelector( '#cp-quality-note' );
		const preview = box.querySelector( '.cp-preview' );
		const empty = box.querySelector( '[data-preview-empty]' );
		const originalImage = box.querySelector( '[data-original]' );
		const compressedImage = box.querySelector( '[data-compressed]' );
		const originalSize = box.querySelector( '[data-original-size]' );
		const newSize = box.querySelector( '[data-new-size]' );
		const name = box.querySelector( '[data-preview-name]' );
		const next = box.querySelector( '[data-preview-next]' );
		let source = null;
		let bitmap = null;
		let urls = [];
		let timer = 0;
		let run = 0;
		let index = 0;
		// Zoom applies to both sides, so they always show the same detail. 50% by default.
		const zoomLevels = [ 0.25, 0.5, 1, 2 ];
		let zoom = 1;
		const zoomOut = box.querySelector( '[data-zoom-out]' );
		const zoomIn = box.querySelector( '[data-zoom-in]' );
		const zoomValue = box.querySelector( '[data-zoom-value]' );
		const applyZoom = () => {
			const scale = zoomLevels[ zoom ];
			if ( bitmap ) {
				[ originalImage, compressedImage ].forEach( ( image ) => { image.style.width = Math.round( bitmap.width * scale ) + 'px'; } );
			}
			zoomValue.textContent = Math.round( scale * 100 ) + '%';
			zoomOut.disabled = 0 === zoom;
			zoomIn.disabled = zoomLevels.length - 1 === zoom;
		};
		zoomOut.addEventListener( 'click', () => { zoom = Math.max( 0, zoom - 1 ); applyZoom(); } );
		zoomIn.addEventListener( 'click', () => { zoom = Math.min( zoomLevels.length - 1, zoom + 1 ); applyZoom(); } );

		const describe = () => {
			const quality = Number( range.value );
			const band = ( data.bands || [] ).find( ( item ) => quality >= item[ 0 ] );
			output.textContent = quality + ' / 100';
			note.textContent = band ? band[ 1 ] : '';
			range.style.setProperty( '--cp-fill', ( ( quality - 1 ) / 99 ) * 100 + '%' );
		};
		const show = ( image, blob, slot ) => {
			if ( urls[ slot ] ) {
				URL.revokeObjectURL( urls[ slot ] );
			}
			urls[ slot ] = URL.createObjectURL( blob );
			image.src = urls[ slot ];
		};
		const encode = async () => {
			if ( ! bitmap ) {
				return;
			}
			const current = ++run;
			const canvas = document.createElement( 'canvas' );
			canvas.width = bitmap.width;
			canvas.height = bitmap.height;
			canvas.getContext( '2d' ).drawImage( bitmap, 0, 0 );
			const blob = await new Promise( ( resolve ) => canvas.toBlob( resolve, 'image/webp', Number( range.value ) / 100 ) );
			canvas.width = 0;
			canvas.height = 0;
			if ( current !== run ) {
				return;
			}
			if ( ! blob || 'image/webp' !== blob.type ) {
				preview.hidden = true;
				return;
			}
			show( compressedImage, blob, 1 );
			const saved = Math.round( ( 1 - blob.size / source.size ) * 100 );
			newSize.textContent = size( blob.size ) + ( saved > 0 ? ', ' + labels.smaller.replace( '%s', saved ).replace( '%%', '%' ) : '' );
		};
		const label = () => {
			next.textContent = 0 === items[ index ] ? labels.own : ( index === items.length - 1 && data.sample ? labels.back : labels.next );
			next.hidden = items.length < 2;
		};
		const load = async () => {
			const sample = 0 === items[ index ];
			const response = await fetch( data.url + '?action=compressino_preview&id=' + ( sample ? 'sample' : items[ index ] ) + '&_ajax_nonce=' + data.nonce, { credentials: 'same-origin' } );
			if ( ! response.ok ) {
				throw new Error( 'preview' );
			}
			source = await response.blob();
			if ( bitmap && bitmap.close ) {
				bitmap.close();
			}
			bitmap = await createImageBitmap( source, { imageOrientation: 'from-image' } );
			show( originalImage, source, 0 );
			applyZoom();
			originalSize.textContent = size( source.size );
			name.textContent = sample ? labels.sample : decodeURIComponent( response.headers.get( 'X-Compressino-Name' ) || '' );
			label();
			preview.hidden = false;
			await encode();
		};
		const fail = () => {
			preview.hidden = true;
			empty.hidden = false;
		};

		range.addEventListener( 'input', () => {
			describe();
			window.clearTimeout( timer );
			timer = window.setTimeout( encode, 250 );
		} );
		next.addEventListener( 'click', () => {
			index = ( index + 1 ) % items.length;
			load().catch( fail );
		} );
		describe();
		if ( ! items.length || ! window.createImageBitmap ) {
			empty.hidden = items.length ? true : false;
			return;
		}
		label();
		load().catch( fail );
	};

	window.compressino = { compress, attach };
	hookMediaUploader();
	hookApiFetch();
	document.addEventListener( 'DOMContentLoaded', () => {
		hookMediaUploader();
		hookApiFetch();
		setupQuality();
	} );
	window.addEventListener( 'load', hookAddNewScreen );
})();