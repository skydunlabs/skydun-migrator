( function () {
	'use strict';

	var settings = window.skyMigrate;

	if ( ! settings ) {
		return;
	}

	// Fill the "This website" card. Text only, never HTML.
	var card = document.getElementById( 'sky-migrate-preflight' );

	function fact( name, text ) {
		var element = card.querySelector( '[data-fact="' + name + '"]' );

		if ( element ) {
			element.textContent = text;
		}
	}

	function checks( items ) {
		var list = card.querySelector( '[data-checks]' );

		list.textContent = '';
		items.forEach( function ( item ) {
			var li = document.createElement( 'li' );

			li.className = item.ok ? 'is-ok' : 'is-warning';
			li.textContent = item.label;
			list.appendChild( li );
		} );
	}

	if ( card ) {
		var body = new FormData();

		body.append( 'action', 'sky_migrate_preflight' );
		body.append( '_ajax_nonce', settings.nonce );

		fetch( settings.ajaxUrl, { method: 'POST', body: body, credentials: 'same-origin' } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( reply ) {
				if ( ! reply || ! reply.success ) {
					throw new Error( 'preflight' );
				}

				fact( 'database', reply.data.database );
				fact( 'files', reply.data.files );
				checks( reply.data.checks );
			} )
			.catch( function () {
				fact( 'database', '—' );
				fact( 'files', '—' );
				checks( [ { ok: false, label: settings.i18n.failed } ] );
			} );
	}

	// Catch keys that are obviously not migration keys before submitting.
	var form = document.getElementById( 'sky-migrate-connect' );

	if ( form ) {
		var key = document.getElementById( 'sky-migrate-key' );
		var error = document.getElementById( 'sky-migrate-key-error' );

		form.addEventListener( 'submit', function ( event ) {
			var value = key.value.replace( /\s+/g, '' );

			if ( value.indexOf( settings.keyPrefix ) !== 0 ) {
				event.preventDefault();
				error.textContent = settings.i18n.badKey;
				error.hidden = false;
				key.setAttribute( 'aria-invalid', 'true' );
				key.focus();
			}
		} );

		key.addEventListener( 'input', function () {
			error.hidden = true;
			key.removeAttribute( 'aria-invalid' );
		} );
	}
} )();
