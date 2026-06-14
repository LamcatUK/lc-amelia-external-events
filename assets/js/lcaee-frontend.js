/**
 * Amelia External Events - Front-end
 *
 * For Amelia events tagged EXTERNAL (matched by name via the localized data blob):
 *   - relabels the booking button to "Find out more"
 *   - flags the card so CSS can hide price/capacity/spots
 *   - intercepts clicks anywhere on the card and opens the external URL in a new tab
 *
 * The public Amelia widget is a Vue SPA that re-renders, so we use a MutationObserver
 * to re-apply the labels, and a capture-phase click listener (which runs before Vue's
 * own handlers) to reliably redirect instead of opening the booking flow.
 *
 * Two surfaces are handled (class names verified against Amelia v3 compiled assets):
 *   - List card:     .am-ec          (name .am-ec__info-name,      button .am-ec__actions-btn)
 *   - Calendar card: .am-ecs__side-card (name .am-ecs__side-card__name, button .am-ecs__side-card__footer)
 */
( function () {
	'use strict';

	var data = window.lcaeeData || {};
	var events = Array.isArray( data.events ) ? data.events : [];
	var label = data.label || 'Find out more';

	if ( ! events.length ) {
		return;
	}

	// Build a normalized name -> url lookup.
	var urlByName = {};
	events.forEach( function ( ev ) {
		if ( ev && ev.name ) {
			urlByName[ normalize( ev.name ) ] = ev.url;
		}
	} );

	function normalize( value ) {
		return String( value ).replace( /\s+/g, ' ' ).trim().toLowerCase();
	}

	function urlForName( name ) {
		if ( ! name ) {
			return null;
		}
		return urlByName[ normalize( name ) ] || null;
	}

	// The two card surfaces: root selector + the name element within it.
	var SURFACES = [
		{ card: '.am-ec', name: '.am-ec__info-name', button: '.am-ec__actions-btn' },
		{ card: '.am-ecs__side-card', name: '.am-ecs__side-card__name', button: '.am-ecs__side-card__footer' }
	];

	/**
	 * Resolve the external URL for whichever card contains the given element.
	 *
	 * @param {Element} el An element inside (or equal to) a card.
	 * @return {?string} The external URL, or null if the card is not external.
	 */
	function urlForElement( el ) {
		for ( var i = 0; i < SURFACES.length; i++ ) {
			var card = el.closest( SURFACES[ i ].card );
			if ( ! card ) {
				continue;
			}
			var nameEl = card.querySelector( SURFACES[ i ].name );
			if ( nameEl ) {
				return urlForName( nameEl.textContent );
			}
		}
		return null;
	}

	function setButtonLabel( btn ) {
		if ( ! btn ) {
			return;
		}
		var inner = btn.querySelector( '.am-button__inner' );
		var target = inner || btn;
		if ( target.textContent !== label ) {
			target.textContent = label;
		}
	}

	/**
	 * Relabel buttons and flag external cards so the CSS can hide price/capacity/spots.
	 */
	function decorate() {
		SURFACES.forEach( function ( surface ) {
			document.querySelectorAll( surface.card ).forEach( function ( card ) {
				var nameEl = card.querySelector( surface.name );
				if ( ! nameEl || ! urlForName( nameEl.textContent ) ) {
					return;
				}
				card.classList.add( 'lcaee-external' );
				setButtonLabel( card.querySelector( surface.button ) );
			} );
		} );
	}

	// Capture-phase interceptor: runs before Vue's bubble-phase handlers, so we can
	// stop the booking flow and redirect instead. Clicking anywhere on an external
	// card (not just the button) opens the URL.
	document.addEventListener(
		'click',
		function ( e ) {
			var target = e.target;
			if ( ! target || ! target.closest ) {
				return;
			}

			var card = target.closest( '.am-ec, .am-ecs__side-card' );
			if ( ! card ) {
				return;
			}

			var url = urlForElement( card );
			if ( ! url ) {
				return;
			}

			e.preventDefault();
			e.stopPropagation();
			if ( e.stopImmediatePropagation ) {
				e.stopImmediatePropagation();
			}

			window.open( url, '_blank', 'noopener' );
		},
		true
	);

	// Re-apply labels as the Vue app renders / re-renders, with light debouncing.
	var scheduled = false;
	function schedule() {
		if ( scheduled ) {
			return;
		}
		scheduled = true;
		setTimeout( function () {
			scheduled = false;
			decorate();
		}, 50 );
	}

	function start() {
		decorate();
		if ( window.MutationObserver && document.body ) {
			new MutationObserver( schedule ).observe( document.body, {
				childList: true,
				subtree: true
			} );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', start );
	} else {
		start();
	}
} )();
