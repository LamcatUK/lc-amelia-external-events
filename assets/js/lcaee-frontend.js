/**
 * Amelia External Events - Front-end
 *
 * For Amelia events tagged EXTERNAL (matched by name via the localized data blob):
 *   - relabels the booking button to "Find out more"
 *   - flags the card/dialog so CSS can hide price/capacity/spots
 *   - intercepts the click and opens the external URL in a new tab
 *
 * The public Amelia widget is a Vue SPA that re-renders, so we use a MutationObserver
 * to re-apply the labels, and a capture-phase click listener (which runs before Vue's
 * own handlers) to reliably redirect instead of opening the booking flow.
 *
 * Surfaces handled (class names verified against Amelia v3 compiled assets):
 *   - Event list card:     .am-ec            (name .am-ec__info-name,        button .am-ec__actions-btn)
 *   - Calendar side card:  .am-ecs__side-card (name .am-ecs__side-card__name, button .am-ecs__side-card__footer)
 *   - Calendar event modal: .am-dialog-el / .am-dialog-popup
 *                          (name .am-ec__info-name, button .am-elf__footer .am-button--primary)
 *
 * The calendar month-grid opens a dialog (.am-dialog-el) that lazy-loads the event-list
 * components, so the booking button there is .am-elf__footer .am-button--primary (built at
 * runtime as "am-button--" + category) and sits OUTSIDE the .am-ec card - hence handled
 * as its own surface.
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

	// A surface = a container element + how to find the event name + the booking button
	// within it. Order matters: most specific card first, dialog wrappers last (fallback).
	var DIALOG_BUTTON = '.am-elf__footer .am-button--primary, .am-elf__footer .am-button--waiting';
	var SURFACES = [
		{ card: '.am-ec', name: '.am-ec__info-name', button: '.am-ec__actions-btn' },
		{ card: '.am-ecs__side-card', name: '.am-ecs__side-card__name', button: '.am-ecs__side-card__footer' },
		{ card: '.am-dialog-el', name: '.am-ec__info-name', button: DIALOG_BUTTON },
		{ card: '.am-dialog-popup', name: '.am-ec__info-name', button: DIALOG_BUTTON }
	];

	// What should trigger the redirect when clicked. Simple cards are clickable anywhere;
	// the dialog is only triggered by its primary/waiting booking button (so the close
	// button, scrolling, etc. still work).
	var CLICK_SELECTOR = '.am-ec, .am-ecs__side-card, ' + DIALOG_BUTTON;

	/**
	 * Resolve the external URL for whichever surface contains the given element.
	 *
	 * @param {Element} el An element inside (or equal to) a card/dialog.
	 * @return {?string} The external URL, or null if not external.
	 */
	function urlForElement( el ) {
		for ( var i = 0; i < SURFACES.length; i++ ) {
			var container = el.closest( SURFACES[ i ].card );
			if ( ! container ) {
				continue;
			}
			var nameEl = container.querySelector( SURFACES[ i ].name );
			if ( nameEl ) {
				var url = urlForName( nameEl.textContent );
				if ( url ) {
					return url;
				}
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
	 * Relabel buttons and flag external surfaces so the CSS can hide price/capacity/spots.
	 */
	function decorate() {
		SURFACES.forEach( function ( surface ) {
			document.querySelectorAll( surface.card ).forEach( function ( container ) {
				var nameEl = container.querySelector( surface.name );
				if ( ! nameEl || ! urlForName( nameEl.textContent ) ) {
					return;
				}
				container.classList.add( 'lcaee-external' );
				setButtonLabel( container.querySelector( surface.button ) );
			} );
		} );
	}

	// Capture-phase interceptor: runs before Vue's bubble-phase handlers, so we can
	// stop the booking flow and redirect instead.
	document.addEventListener(
		'click',
		function ( e ) {
			var target = e.target;
			if ( ! target || ! target.closest ) {
				return;
			}

			var trigger = target.closest( CLICK_SELECTOR );
			if ( ! trigger ) {
				return;
			}

			var url = urlForElement( trigger );
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
