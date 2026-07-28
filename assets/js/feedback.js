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

	var feedback;

	try {
		feedback = window.MarkUpSDK.init( {
			publicKey: config.publicKey,
			markupId: config.markupId,
			// Survives navigation within the tab without persisting to disk.
			sessionStorage: 'sessionStorage',
			debug: !! config.debug,
		} );
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

	Promise.resolve( feedback.render( config.render || {} ) )
		.then( function () {
			log( 'Ready.' );
		} )
		.catch( function ( err ) {
			log( 'Could not display the feedback bar:', err && err.message, err && err.code );
		} );

	// Exposed for debugging and for other code that wants to drive the bar.
	window.mbmLiveFeedback.instance = feedback;
}() );
