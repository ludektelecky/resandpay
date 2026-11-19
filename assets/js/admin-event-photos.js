/**
 * ReserveAndPay – editor akce, záložka Fotky z akce (#45).
 * Fotky se v prohlížeči zmenší (delší strana rapPh.max px) a znovu uloží jako JPEG – tím se
 * zmenší pro nahrání a odstraní se z nich údaje EXIF včetně polohy (GPS). Nahrávají se po
 * jedné; pořadí (přetažením) a odebrání se uloží spolu s akcí (skryté pole rap_photos_order).
 */
( function () {
	'use strict';
	var input = document.getElementById( 'rap-ph-upload' );
	var list = document.getElementById( 'rap-ph-list' );
	var order = document.getElementById( 'rap_photos_order' );
	if ( ! input || ! list || ! order || ! window.rapPh || ! window.fetch ) {
		return;
	}
	var t = window.rapPh.i18n;
	var max = parseInt( window.rapPh.max, 10 ) || 2048;
	var progress = document.getElementById( 'rap-ph-progress' );
	var count = document.getElementById( 'rap-ph-count' );

	function sync() {
		var ids = Array.prototype.map.call( list.querySelectorAll( '.rap-ph-admin__item' ), function ( li ) {
			return li.getAttribute( 'data-id' );
		} );
		order.value = ids.join( ',' );
		if ( count ) {
			count.textContent = count.textContent.replace( /\d+/, String( ids.length ) );
		}
	}

	function loadImage( file ) {
		if ( window.createImageBitmap ) {
			return createImageBitmap( file, { imageOrientation: 'from-image' } ).catch( function () {
				return createImageBitmap( file );
			} );
		}
		return new Promise( function ( ok, fail ) {
			var img = new Image();
			img.onload = function () {
				ok( img );
			};
			img.onerror = fail;
			img.src = URL.createObjectURL( file );
		} );
	}

	/** Zmenšená kopie jako JPEG (bez EXIF); když se nepovede, pošle se originál. */
	function shrink( file ) {
		return loadImage( file ).then( function ( img ) {
			var w = img.width, h = img.height, k = Math.min( 1, max / Math.max( w, h ) );
			var c = document.createElement( 'canvas' );
			c.width = Math.round( w * k );
			c.height = Math.round( h * k );
			var ctx = c.getContext( '2d' );
			ctx.fillStyle = '#fff'; // Průhledné PNG na bílém podkladu.
			ctx.fillRect( 0, 0, c.width, c.height );
			ctx.drawImage( img, 0, 0, c.width, c.height );
			return new Promise( function ( ok ) {
				c.toBlob( function ( b ) {
					ok( b || file );
				}, 'image/jpeg', 0.86 );
			} );
		} ).catch( function () {
			return file;
		} );
	}

	function send( file ) {
		return shrink( file ).then( function ( blob ) {
			var fd = new FormData();
			fd.append( 'action', 'resandpay_photo_upload' );
			fd.append( 'nonce', window.rapPh.nonce );
			fd.append( 'post_id', input.getAttribute( 'data-post' ) );
			fd.append( 'photo', blob, blob === file ? file.name : file.name.replace( /\.[^.]+$/, '' ) + '.jpg' );
			return fetch( window.rapPh.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } );
		} ).then( function ( r ) {
			return r.json();
		} ).then( function ( j ) {
			if ( ! j || ! j.success ) {
				throw new Error( ( j && j.data && j.data.message ) || t.error );
			}
			list.insertAdjacentHTML( 'beforeend', j.data.html );
			sync();
		} );
	}

	input.addEventListener( 'change', function () {
		var files = Array.prototype.slice.call( input.files || [] );
		var errors = [];
		input.disabled = true;
		( function next( i ) {
			if ( i >= files.length ) {
				input.value = '';
				input.disabled = false;
				progress.textContent = errors.length ? t.error + ': ' + errors.join( '; ' ) : t.done;
				return;
			}
			progress.textContent = t.uploading + ' ' + ( i + 1 ) + ' / ' + files.length + ' – ' + files[ i ].name;
			send( files[ i ] ).catch( function ( e ) {
				errors.push( files[ i ].name + ' (' + e.message + ')' );
			} ).then( function () {
				next( i + 1 );
			} );
		}( 0 ) );
	} );

	list.addEventListener( 'click', function ( e ) {
		var b = e.target.closest( '.rap-ph-admin__remove' );
		if ( b ) {
			b.closest( '.rap-ph-admin__item' ).remove();
			sync();
		}
	} );

	// Pořadí přetažením.
	var drag = null;
	list.addEventListener( 'dragstart', function ( e ) {
		drag = e.target.closest( '.rap-ph-admin__item' );
		if ( drag ) {
			drag.classList.add( 'is-drag' );
			e.dataTransfer.effectAllowed = 'move';
			e.dataTransfer.setData( 'text/plain', drag.getAttribute( 'data-id' ) );
		}
	} );
	list.addEventListener( 'dragover', function ( e ) {
		var over = e.target.closest( '.rap-ph-admin__item' );
		if ( ! drag || ! over || over === drag ) {
			return;
		}
		e.preventDefault();
		var r = over.getBoundingClientRect();
		list.insertBefore( drag, e.clientX > r.left + r.width / 2 ? over.nextSibling : over );
	} );
	list.addEventListener( 'dragend', function () {
		if ( drag ) {
			drag.classList.remove( 'is-drag' );
			drag = null;
			sync();
		}
	} );

	var notify = document.getElementById( 'rap-ph-notify' );
	if ( notify ) {
		notify.addEventListener( 'click', function ( e ) {
			if ( ! window.confirm( t.confirm ) ) {
				e.preventDefault();
			}
		} );
	}
}() );
