/**
 * ReserveAndPay – fotky z proběhlé akce (#45): „Zobrazit další“ a prohlížeč fotek přes celou
 * obrazovku (šipky, klávesy ←/→, tah prstem, Esc). Bez JavaScriptu odkaz náhledu otevře fotku.
 */
( function () {
	'use strict';
	var t = window.rapPhotos || {};

	function init( sec ) {
		if ( sec.dataset.rapReady ) {
			return;
		}
		sec.dataset.rapReady = '1';
		var more = sec.querySelector( '[data-rap-more]' );
		if ( more ) {
			more.addEventListener( 'click', function () {
				var step = parseInt( more.getAttribute( 'data-rap-more' ), 10 ) || 24;
				var hidden = sec.querySelectorAll( '.rap-photos__cell[hidden]' );
				for ( var i = 0; i < hidden.length && i < step; i++ ) {
					hidden[ i ].hidden = false;
				}
				if ( hidden.length <= step ) {
					more.parentNode.removeChild( more );
				}
			} );
		}
		var items = Array.prototype.slice.call( sec.querySelectorAll( '.rap-photos__item' ) );
		items.forEach( function ( a, i ) {
			a.addEventListener( 'click', function ( e ) {
				if ( e.metaKey || e.ctrlKey || e.shiftKey || e.button ) {
					return;
				}
				e.preventDefault();
				open( items, i, a );
			} );
		} );
	}

	var box, img, count, prev, next, list = [], cur = 0, opener = null, startX = null;

	function build() {
		box = document.createElement( 'div' );
		box.className = 'rap-lightbox';
		box.hidden = true;
		box.setAttribute( 'role', 'dialog' );
		box.setAttribute( 'aria-modal', 'true' );
		box.setAttribute( 'aria-label', t.label || '' );
		box.innerHTML = '<div class="rap-lightbox__bar"><span class="rap-lightbox__count" aria-live="polite"></span><button type="button" class="rap-lightbox__close">×</button></div>' +
			'<button type="button" class="rap-lightbox__prev">‹</button><img class="rap-lightbox__img" alt=""><button type="button" class="rap-lightbox__next">›</button>';
		img = box.querySelector( '.rap-lightbox__img' );
		count = box.querySelector( '.rap-lightbox__count' );
		prev = box.querySelector( '.rap-lightbox__prev' );
		next = box.querySelector( '.rap-lightbox__next' );
		var close = box.querySelector( '.rap-lightbox__close' );
		close.setAttribute( 'aria-label', t.close || 'Close' );
		prev.setAttribute( 'aria-label', t.prev || 'Previous' );
		next.setAttribute( 'aria-label', t.next || 'Next' );
		close.addEventListener( 'click', hide );
		prev.addEventListener( 'click', function () { go( cur - 1 ); } );
		next.addEventListener( 'click', function () { go( cur + 1 ); } );
		box.addEventListener( 'click', function ( e ) {
			if ( e.target === box ) {
				hide();
			}
		} );
		box.addEventListener( 'touchstart', function ( e ) {
			startX = e.touches.length === 1 ? e.touches[ 0 ].clientX : null;
		}, { passive: true } );
		box.addEventListener( 'touchend', function ( e ) {
			if ( null === startX || ! e.changedTouches.length ) {
				return;
			}
			var dx = e.changedTouches[ 0 ].clientX - startX;
			startX = null;
			if ( Math.abs( dx ) > 50 ) {
				go( dx < 0 ? cur + 1 : cur - 1 );
			}
		} );
		document.addEventListener( 'keydown', function ( e ) {
			if ( box.hidden ) {
				return;
			}
			if ( 'Escape' === e.key ) {
				hide();
			} else if ( 'ArrowLeft' === e.key ) {
				go( cur - 1 );
			} else if ( 'ArrowRight' === e.key ) {
				go( cur + 1 );
			} else if ( 'Tab' === e.key ) {
				// Fokus zůstává v prohlížeči.
				var f = Array.prototype.filter.call( box.querySelectorAll( 'button' ), function ( b ) { return ! b.hidden; } );
				var i = f.indexOf( document.activeElement );
				e.preventDefault();
				f[ ( i + ( e.shiftKey ? -1 : 1 ) + f.length ) % f.length ].focus();
			}
		} );
		document.body.appendChild( box );
	}

	function preload( i ) {
		if ( list[ i ] ) {
			( new Image() ).src = list[ i ].href;
		}
	}

	function go( i ) {
		if ( i < 0 || i >= list.length ) {
			return;
		}
		cur = i;
		var a = list[ i ], th = a.querySelector( 'img' );
		img.src = a.href;
		img.alt = th ? th.alt : '';
		count.textContent = ( i + 1 ) + ' / ' + list.length;
		prev.hidden = 0 === i;
		next.hidden = i === list.length - 1;
		preload( i + 1 );
		preload( i - 1 );
	}

	function open( items, i, a ) {
		if ( ! box ) {
			build();
		}
		list = items;
		opener = a;
		box.hidden = false;
		document.body.classList.add( 'rap-lightbox-open' );
		go( i );
		box.querySelector( '.rap-lightbox__close' ).focus();
	}

	function hide() {
		box.hidden = true;
		img.removeAttribute( 'src' );
		document.body.classList.remove( 'rap-lightbox-open' );
		// Náhled poslední prohlížené fotky (i skrytý za „Zobrazit další“ odkryjeme).
		var a = list[ cur ] || opener;
		if ( a ) {
			var cell = a.closest( '.rap-photos__cell' );
			if ( cell ) {
				cell.hidden = false;
			}
			a.focus();
		}
	}

	function run() {
		document.querySelectorAll( '[data-rap-photos]' ).forEach( init );
	}
	if ( 'loading' !== document.readyState ) {
		run();
	} else {
		document.addEventListener( 'DOMContentLoaded', run );
	}
}() );
