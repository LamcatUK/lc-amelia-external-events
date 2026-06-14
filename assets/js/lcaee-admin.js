/**
 * Amelia External Events - Admin
 * Saves / removes the external URL for each EXTERNAL-tagged event.
 */
( function ( $ ) {
	'use strict';

	function setStatus( $row, message, isError ) {
		var $status = $row.find( '.lcaee-status' );
		$status.text( message ).css( 'color', isError ? '#b32d2e' : '#1a7d33' );
		if ( message && ! isError ) {
			setTimeout( function () {
				$status.text( '' );
			}, 4000 );
		}
	}

	function isValidUrl( value ) {
		if ( ! value ) {
			return true; // Empty is allowed: it clears the link.
		}
		return /^https?:\/\/.+/i.test( value );
	}

	$( document ).ready( function () {
		$( '.lcaee-save' ).on( 'click', function () {
			var $row = $( this ).closest( 'tr' );
			var eventId = $row.data( 'event-id' );
			var url = $.trim( $row.find( '.lcaee-url-input' ).val() );

			if ( ! isValidUrl( url ) ) {
				setStatus( $row, lcaeeAdmin.strings.invalidUrl, true );
				return;
			}

			setStatus( $row, lcaeeAdmin.strings.saving, false );

			$.post(
				lcaeeAdmin.ajaxurl,
				{
					action: 'lcaee_save_url',
					nonce: lcaeeAdmin.nonce,
					event_id: eventId,
					url: url
				},
				function ( response ) {
					if ( response && response.success ) {
						setStatus( $row, response.data.message || lcaeeAdmin.strings.saved, false );
						if ( response.data.removed ) {
							$row.find( '.lcaee-remove' ).hide();
						} else {
							$row.find( '.lcaee-remove' ).show();
						}
					} else {
						setStatus( $row, ( response && response.data && response.data.message ) || lcaeeAdmin.strings.error, true );
					}
				}
			).fail( function () {
				setStatus( $row, lcaeeAdmin.strings.error, true );
			} );
		} );

		$( '.lcaee-remove' ).on( 'click', function () {
			var $row = $( this ).closest( 'tr' );
			var eventId = $row.data( 'event-id' );

			if ( ! window.confirm( lcaeeAdmin.strings.confirmDelete ) ) {
				return;
			}

			$.post(
				lcaeeAdmin.ajaxurl,
				{
					action: 'lcaee_delete_url',
					nonce: lcaeeAdmin.nonce,
					event_id: eventId
				},
				function ( response ) {
					if ( response && response.success ) {
						$row.find( '.lcaee-url-input' ).val( '' );
						$row.find( '.lcaee-remove' ).hide();
						setStatus( $row, response.data.message || lcaeeAdmin.strings.removed, false );
					} else {
						setStatus( $row, ( response && response.data && response.data.message ) || lcaeeAdmin.strings.error, true );
					}
				}
			).fail( function () {
				setStatus( $row, lcaeeAdmin.strings.error, true );
			} );
		} );
	} );
} )( jQuery );
