/**
 * ReserveAndPay – přehrávač videa materiálu s vodoznakem (#50).
 * Vodoznak je překryv v HTML nad videem; aby zůstal vidět i na celé obrazovce, celou obrazovku
 * přepíná tlačítko pluginu (a dvojklik) na obalu videa, ne nativní celá obrazovka videa.
 */
( function () {
	'use strict';
	function fsEl() {
		return document.fullscreenElement || document.webkitFullscreenElement || null;
	}
	function toggle( box ) {
		if ( fsEl() ) {
			( document.exitFullscreen || document.webkitExitFullscreen ).call( document );
			return;
		}
		var req = box.requestFullscreen || box.webkitRequestFullscreen;
		if ( req ) {
			req.call( box );
		}
	}
	function init() {
		document.querySelectorAll( '[data-rap-player]' ).forEach( function ( box ) {
			if ( box.dataset.rapReady ) {
				return;
			}
			box.dataset.rapReady = '1';
			var btn = box.querySelector( '.rap-mat-video__fs' );
			var video = box.querySelector( 'video' );
			if ( btn && ! ( box.requestFullscreen || box.webkitRequestFullscreen ) ) {
				btn.hidden = true; // Prohlížeč bez celé obrazovky prvku (iPhone) – video zůstává v okně s vodoznakem.
			}
			if ( btn ) {
				btn.addEventListener( 'click', function () {
					toggle( box );
				} );
			}
			if ( video ) {
				video.addEventListener( 'dblclick', function ( e ) {
					e.preventDefault();
					toggle( box );
				} );
				// Nativní celá obrazovka videa (bez vodoznaku) – přesměrovat na obal.
				video.addEventListener( 'webkitbeginfullscreen', function () {
					if ( video.webkitExitFullscreen ) {
						video.webkitExitFullscreen();
					}
				} );
			}
		} );
	}
	// Nativní celá obrazovka samotného videa (např. z nabídky prohlížeče) by vodoznak skryla – přepnout na obal.
	document.addEventListener( 'fullscreenchange', function () {
		var el = fsEl();
		if ( el && 'VIDEO' === el.tagName && el.closest( '[data-rap-player]' ) ) {
			var box = el.closest( '[data-rap-player]' );
			document.exitFullscreen().then( function () {
				if ( box.requestFullscreen ) {
					box.requestFullscreen();
				}
			} ).catch( function () {} );
		}
	} );
	if ( document.readyState !== 'loading' ) {
		init();
	} else {
		document.addEventListener( 'DOMContentLoaded', init );
	}
} )();
