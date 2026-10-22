/* ReserveAndPay – načtení QR kódu kamerou na stránce Odbavení.
 * Použije vestavěný BarcodeDetector (Chrome/Android), jinak jsQR (Safari/iPhone).
 * Z QR platby (SPD) vezme variabilní symbol (X-VS) a vyhledá rezervaci. */
( function () {
	'use strict';
	var btn = document.getElementById( 'rap-scan-btn' );
	var box = document.getElementById( 'rap-scan' );
	var input = document.getElementById( 'rap-vs' );
	if ( ! btn || ! box || ! input ) {
		return;
	}
	var video = box.querySelector( 'video' );
	var msg = box.querySelector( '.rap-scan__msg' );
	var stopBtn = box.querySelector( '.rap-scan__stop' );
	var canvas = document.createElement( 'canvas' );
	var ctx = canvas.getContext( '2d', { willReadFrequently: true } );
	var stream = null;
	var detector = null;
	var running = false;
	var t = window.rapScanText || {};

	function vsFrom( text ) {
		var m = /X-VS:(\d{1,10})/i.exec( text || '' );
		if ( m ) {
			return m[ 1 ];
		}
		var d = ( text || '' ).replace( /\s+/g, '' );
		return /^\d{1,10}$/.test( d ) ? d : '';
	}

	function stop() {
		running = false;
		if ( stream ) {
			stream.getTracks().forEach( function ( tr ) {
				tr.stop();
			} );
			stream = null;
		}
		box.hidden = true;
		btn.hidden = false;
	}

	function found( text ) {
		var vs = vsFrom( text );
		if ( ! vs ) {
			msg.textContent = t.notPayment || 'QR kód neobsahuje variabilní symbol.';
			return false;
		}
		stop();
		input.value = vs;
		input.form.submit();
		return true;
	}

	function tick() {
		if ( ! running ) {
			return;
		}
		if ( video.readyState === video.HAVE_ENOUGH_DATA ) {
			if ( detector ) {
				detector.detect( video ).then( function ( codes ) {
					if ( codes.length && found( codes[ 0 ].rawValue ) ) {
						return;
					}
					requestAnimationFrame( tick );
				} ).catch( function () {
					requestAnimationFrame( tick );
				} );
				return;
			}
			canvas.width = video.videoWidth;
			canvas.height = video.videoHeight;
			ctx.drawImage( video, 0, 0, canvas.width, canvas.height );
			var img = ctx.getImageData( 0, 0, canvas.width, canvas.height );
			var code = window.jsQR ? window.jsQR( img.data, img.width, img.height, { inversionAttempts: 'dontInvert' } ) : null;
			if ( code && found( code.data ) ) {
				return;
			}
		}
		requestAnimationFrame( tick );
	}

	btn.addEventListener( 'click', function () {
		if ( ! navigator.mediaDevices || ! navigator.mediaDevices.getUserMedia ) {
			window.alert( t.noCamera || 'Kamera není v tomto prohlížeči dostupná (je potřeba HTTPS).' );
			return;
		}
		box.hidden = false;
		btn.hidden = true;
		msg.textContent = t.aim || 'Namiřte kameru na QR kód z e-mailu nebo platebního pokynu.';
		if ( 'BarcodeDetector' in window ) {
			try {
				detector = new window.BarcodeDetector( { formats: [ 'qr_code' ] } );
			} catch ( e ) {
				detector = null;
			}
		}
		navigator.mediaDevices.getUserMedia( { video: { facingMode: 'environment' }, audio: false } ).then( function ( s ) {
			stream = s;
			video.srcObject = s;
			video.setAttribute( 'playsinline', '' );
			video.play();
			running = true;
			requestAnimationFrame( tick );
		} ).catch( function () {
			msg.textContent = t.denied || 'Přístup ke kameře byl zamítnut.';
		} );
	} );
	stopBtn.addEventListener( 'click', stop );
} )();
