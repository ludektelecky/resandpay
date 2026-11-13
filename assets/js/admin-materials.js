/**
 * ReserveAndPay – nahrávání souborů materiálu po částech do chráněné složky (SWD kap. 15.1, R11).
 * Části jdou postupně (každá ověří pořadí na serveru); po poslední se do tabulky přidá řádek
 * souboru – uloží se spolu s materiálem.
 */
( function () {
	'use strict';
	var input = document.getElementById( 'rap-mat-upload' );
	if ( ! input || ! window.rapMat || ! window.fetch ) {
		return;
	}
	var progress = document.getElementById( 'rap-mat-progress' );
	var tbody = document.getElementById( 'rap-mat-files' );
	var t = window.rapMat.i18n;
	var chunk = parseInt( window.rapMat.chunk, 10 ) || 1048576; // wp_localize_script předává čísla jako text.

	function uid() {
		var s = '';
		var c = 'abcdefghijklmnopqrstuvwxyz0123456789';
		for ( var i = 0; i < 24; i++ ) {
			s += c.charAt( Math.floor( Math.random() * c.length ) );
		}
		return s;
	}

	function sendChunk( file, id, offset ) {
		var end = Math.min( offset + chunk, file.size );
		var fd = new FormData();
		fd.append( 'action', 'resandpay_mat_upload' );
		fd.append( 'nonce', window.rapMat.nonce );
		fd.append( 'post_id', input.getAttribute( 'data-post' ) );
		fd.append( 'upload_id', id );
		fd.append( 'name', file.name );
		fd.append( 'offset', String( offset ) );
		fd.append( 'total', String( file.size ) );
		fd.append( 'chunk', file.slice( offset, end ), 'chunk' );
		return fetch( window.rapMat.ajax, { method: 'POST', body: fd, credentials: 'same-origin' } )
			.then( function ( r ) {
				return r.json();
			} )
			.then( function ( j ) {
				if ( ! j || ! j.success ) {
					throw new Error( ( j && j.data && j.data.message ) || t.error );
				}
				progress.textContent = t.uploading + ' ' + file.name + ' – ' + Math.round( ( j.data.received / Math.max( 1, file.size ) ) * 100 ) + ' %';
				if ( j.data.done ) {
					return j.data;
				}
				return sendChunk( file, id, j.data.received );
			} );
	}

	function uploadAll( files, i ) {
		if ( i >= files.length ) {
			progress.textContent = t.done;
			input.value = '';
			input.disabled = false;
			return;
		}
		var f = files[ i ];
		if ( ! f.size ) {
			uploadAll( files, i + 1 );
			return;
		}
		sendChunk( f, uid(), 0 )
			.then( function ( data ) {
				var empty = document.querySelector( '.rap-mat-empty' );
				if ( empty ) {
					empty.remove();
				}
				tbody.insertAdjacentHTML( 'beforeend', data.row );
				uploadAll( files, i + 1 );
			} )
			.catch( function ( e ) {
				progress.textContent = t.error + ': ' + f.name + ' – ' + e.message;
				input.disabled = false;
			} );
	}

	input.addEventListener( 'change', function () {
		if ( ! input.files || ! input.files.length ) {
			return;
		}
		input.disabled = true;
		uploadAll( Array.prototype.slice.call( input.files ), 0 );
	} );
} )();
