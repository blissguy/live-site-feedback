/**
 * Starts the MarkUp feedback bar.
 *
 * Whether the bar loads at all, and who sees it, is decided in PHP — by the time
 * this file runs those checks have already passed.
 */
( function () {
	'use strict';

	var config = window.mbmLiveFeedback;

	if ( ! config || ! config.publicKey || ! config.markupId ) {
		return;
	}

	var log = function () {
		if ( ! config.debug ) {
			return;
		}

		var args = Array.prototype.slice.call( arguments );

		args.unshift( '[Live Site Feedback]' );
		console.log.apply( console, args );
	};

	if ( typeof window.MarkUpSDK === 'undefined' ) {
		// Either the CDN is unreachable or the file changed and failed its
		// integrity check. Either way there is nothing to start.
		log( 'The MarkUp.io library did not load.' );
		return;
	}

	var options = {
		publicKey: config.publicKey,
		markupId: config.markupId,
		// Survives navigation within the tab without persisting to disk.
		sessionStorage: 'sessionStorage',
		debug: !! config.debug,
	};

	var readToken = function ( response ) {
		return response.json().catch( function () {
			return {};
		} ).then( function ( data ) {
			if ( ! response.ok || ! data || ! data.token ) {
				var err = new Error( ( data && data.message ) || 'token request failed with ' + response.status );
				err.status = response.status;
				throw err;
			}

			return data.token;
		} );
	};

	// When WordPress can vouch for who this is, the person comments under their
	// own name with nothing to sign in to. Otherwise the library offers its own
	// sign-in instead. PHP decides which, so this only has to honour it.
	if ( config.tokenEndpoint ) {
		options.onTokenNeeded = function () {
			log( 'Asking WordPress who this is.' );

			return fetch( config.tokenEndpoint, {
				credentials: 'same-origin',
				headers: { 'X-WP-Nonce': config.nonce },
			} )
				.then( readToken )
				.then( function ( token ) {
					log( 'Identity confirmed.' );

					return token;
				} );
		};

		start();
		return;
	}

	if ( config.guestEndpoint ) {
		startAsGuest();
		return;
	}

	start();

	/**
	 * Somebody without an account here: they give a name and email once, and
	 * WordPress vouches for them as a guest.
	 *
	 * The library asks for a token the moment it starts, so it only starts
	 * once there is somebody to vouch for. Until then the visitor sees our own
	 * button in its place.
	 */
	function startAsGuest() {
		var storeKey = 'mbmLiveFeedbackGuest';
		var guest    = null;

		try {
			guest = JSON.parse( window.localStorage.getItem( storeKey ) || 'null' );
		} catch ( err ) {
			guest = null;
		}

		var askWordPress = function ( who ) {
			return fetch( config.guestEndpoint, {
				method: 'POST',
				credentials: 'same-origin',
				headers: { 'Content-Type': 'application/json' },
				body: JSON.stringify( { name: who.name, email: who.email } ),
			} ).then( readToken );
		};

		var startWith = function ( who, firstToken ) {
			options.onTokenNeeded = function () {
				// The token from the form is used once; after that, each
				// refresh asks WordPress again.
				if ( firstToken ) {
					var token = firstToken;

					firstToken = null;

					return Promise.resolve( token );
				}

				log( 'Asking WordPress for a guest token.' );

				return askWordPress( who ).catch( function ( err ) {
					// Refused rather than unreachable: the saved details are no
					// good any more, so the form comes back next time.
					if ( err.status >= 400 && err.status < 500 && 429 !== err.status ) {
						forget();
					}

					throw err;
				} );
			};

			start();
		};

		var forget = function () {
			try {
				window.localStorage.removeItem( storeKey );
			} catch ( err ) {
				// Storage blocked: nothing was saved, so nothing to remove.
			}
		};

		if ( guest && guest.name && guest.email ) {
			startWith( guest, null );
			return;
		}

		showGuestForm( function ( who ) {
			return askWordPress( who ).then( function ( token ) {
				try {
					window.localStorage.setItem( storeKey, JSON.stringify( who ) );
				} catch ( err ) {
					// Storage blocked: they'll be asked again on the next page.
				}

				startWith( who, token );
			} );
		} );
	}

	/**
	 * Show the "Leave feedback" button, and the form it opens.
	 *
	 * @param {Function} submit Called with { name, email }; returns a promise.
	 */
	function showGuestForm( submit ) {
		var text     = config.guestText || {};
		var position = ( config.render && config.render.position ) || 'bottom-right';
		var theme    = ( config.render && config.render.theme ) || 'auto';

		var button = document.createElement( 'button' );

		button.type        = 'button';
		button.className   = 'mbm-lf-guest-open mbm-lf-guest-open--' + position + ' mbm-lf-theme-' + theme;
		button.textContent = text.open;

		var dialog = document.createElement( 'dialog' );

		dialog.className = 'mbm-lf-guest mbm-lf-theme-' + theme;
		dialog.setAttribute( 'aria-labelledby', 'mbm-lf-guest-title' );
		dialog.innerHTML =
			'<form method="dialog" class="mbm-lf-guest__form">' +
				'<h2 id="mbm-lf-guest-title" class="mbm-lf-guest__title"></h2>' +
				'<p class="mbm-lf-guest__intro"></p>' +
				'<label class="mbm-lf-guest__field"><span data-label="name"></span>' +
					'<input name="name" type="text" autocomplete="name" maxlength="80" required></label>' +
				'<label class="mbm-lf-guest__field"><span data-label="email"></span>' +
					'<input name="email" type="email" autocomplete="email" required></label>' +
				'<p class="mbm-lf-guest__error" role="alert" aria-live="assertive"></p>' +
				'<div class="mbm-lf-guest__actions">' +
					'<button type="button" class="mbm-lf-guest__cancel"></button>' +
					'<button type="submit" class="mbm-lf-guest__submit"></button>' +
				'</div>' +
			'</form>';

		// Wording is set as text, never as markup.
		dialog.querySelector( '.mbm-lf-guest__title' ).textContent  = text.title;
		dialog.querySelector( '.mbm-lf-guest__intro' ).textContent  = text.intro;
		dialog.querySelector( '[data-label="name"]' ).textContent   = text.name;
		dialog.querySelector( '[data-label="email"]' ).textContent  = text.email;
		dialog.querySelector( '.mbm-lf-guest__cancel' ).textContent = text.cancel;
		dialog.querySelector( '.mbm-lf-guest__submit' ).textContent = text.submit;

		var form   = dialog.querySelector( 'form' );
		var error  = dialog.querySelector( '.mbm-lf-guest__error' );
		var submitButton = dialog.querySelector( '.mbm-lf-guest__submit' );

		button.addEventListener( 'click', function () {
			error.textContent = '';

			if ( typeof dialog.showModal === 'function' ) {
				dialog.showModal();
			} else {
				dialog.setAttribute( 'open', '' );
			}

			form.elements.name.focus();
		} );

		dialog.querySelector( '.mbm-lf-guest__cancel' ).addEventListener( 'click', function () {
			dialog.close();
		} );

		form.addEventListener( 'submit', function ( event ) {
			event.preventDefault();

			var who = {
				name: form.elements.name.value.trim(),
				email: form.elements.email.value.trim(),
			};

			submitButton.disabled    = true;
			submitButton.textContent = text.working;
			error.textContent        = '';

			submit( who )
				.then( function () {
					dialog.close();
					dialog.remove();
					button.remove();
				} )
				.catch( function ( err ) {
					log( 'Guest sign-in failed:', err && err.message );
					error.textContent = ( err && err.status && err.message ) ? err.message : text.failed;
				} )
				.then( function () {
					submitButton.disabled    = false;
					submitButton.textContent = text.submit;
				} );
		} );

		document.body.appendChild( button );
		document.body.appendChild( dialog );
	}

	/**
	 * Start the library and show the feedback bar.
	 */
	function start() {
		var feedback;

		try {
			feedback = window.MarkUpSDK.init( options );
		} catch ( err ) {
			log( 'Could not start:', err && err.message, err && err.code );
			return;
		}

		if ( config.debug ) {
			[
				'commenting:start',
				'commenting:stop',
				'thread:open',
				'thread:close',
				'pin:placed',
				'pin:cancelled',
				'comment:reply',
				'comment:resolve',
				'comment:unresolve',
				'comment:delete',
				'comment:edit',
				'comment:reply:edit',
				'comment:reply:delete',
				'thread:priority-changed',
			].forEach( function ( event ) {
				try {
					feedback.on( event, function ( payload ) {
						log( event, payload );
					} );
				} catch ( err ) {
					log( 'Could not listen for', event );
				}
			} );
		}

		var render = config.render || {};

		// Screenshots come from a separate plugin file, loaded only when switched
		// on. Auto mode captures the clicked element as soon as a pin is placed;
		// the button to take another stays in the comment box either way.
		if ( config.screenshot ) {
			if ( window.MarkUpSDK.plugins && typeof window.MarkUpSDK.plugins.screenshot === 'function' ) {
				render.plugins = [ window.MarkUpSDK.plugins.screenshot( { auto: true } ) ];
			} else {
				log( 'The screenshot plugin did not load, so comments go without one.' );
			}
		}

		Promise.resolve( feedback.render( render ) )
			.then( function () {
				log( 'Ready.' );
			} )
			.catch( function ( err ) {
				log( 'Could not display the feedback bar:', err && err.message, err && err.code );
			} );

		// Exposed for debugging and for other code that wants to drive the bar.
		window.mbmLiveFeedback.instance = feedback;
	}
}() );
