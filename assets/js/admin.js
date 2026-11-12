( function () {
	'use strict';

	var config = window.siteMonitorAdmin;

	if ( ! config ) {
		return;
	}

	var button = document.querySelector( '[data-scan-button]' );
	var buttonLabel = button ? button.value : '';
	if ( button && button.form ) {
		button.form.addEventListener( 'submit', function () {
			button.disabled = true;
			button.value = config.labels.starting;
			setText( '[data-scan-feedback]', config.labels.starting );
		} );
	}

	if ( ! config.scanRunning || ! config.statusUrl || ! config.restNonce ) {
		return;
	}
	if ( button ) {
		button.value = config.labels.scanning;
	}

	function setText( selector, value ) {
		var node = document.querySelector( selector );
		if ( node ) {
			node.textContent = value;
		}
	}

	function poll() {
		window.fetch( config.statusUrl, {
			cache: 'no-store',
			credentials: 'same-origin',
			headers: { 'X-WP-Nonce': config.restNonce }
		} ).then( function ( response ) {
			if ( ! response.ok ) {
				throw new Error( 'Status request failed.' );
			}
			return response.json();
		} ).then( function ( data ) {
			var scan = data.latest_scan;
			if ( ! scan || 'string' !== typeof scan.status ) {
				throw new Error( 'Invalid scan status.' );
			}
			setText( '[data-scan-status]', scan.status.charAt( 0 ).toUpperCase() + scan.status.slice( 1 ) );
			setText( '[data-scan-progress]', scan.checked_pages + ' / ' + scan.total_pages + ' ' + config.labels.checked + '; ' + scan.failed_pages + ' ' + config.labels.failed + '.' );
			if ( data.summary ) {
				Object.keys( data.summary ).forEach( function ( key ) {
					setText( '[data-summary="' + ( 'open_incidents' === key ? 'incidents' : key ) + '"]', data.summary[ key ] );
				} );
			}
			if ( 'running' === scan.status ) {
				setText( '[data-scan-feedback]', config.labels.running );
				window.setTimeout( poll, 5000 );
			} else {
				setText( '[data-scan-feedback]', 'completed' === scan.status ? config.labels.completed : config.labels.scanFailed );
				if ( button ) {
					button.disabled = false;
					button.value = buttonLabel;
				}
				if ( 'completed' === scan.status ) {
					window.setTimeout( function () { window.location.reload(); }, 1500 );
				}
			}
		} ).catch( function () {
			setText( '[data-scan-feedback]', config.labels.statusError );
			window.setTimeout( poll, 10000 );
		} );
	}

	window.setTimeout( poll, 1000 );
}() );
