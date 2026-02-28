/* global wpaiimageBilling, jQuery */
( function ( $ ) {
	'use strict';

	var loadingCount = 0;

	function startLoadingCursor() {
		loadingCount++;
		$( 'body' ).css( 'cursor', 'progress' );
	}

	function stopLoadingCursor() {
		loadingCount = Math.max( 0, loadingCount - 1 );
		if ( loadingCount === 0 ) {
			$( 'body' ).css( 'cursor', '' );
		}
	}

	// -------------------------------------------------------------------------
	// Generic billing AJAX helper
	// -------------------------------------------------------------------------

	function billingAjax( action, data, onSuccess, onError ) {
		startLoadingCursor();
		$.ajax( {
			url:     wpaiimageBilling.ajax_url,
			type:    'POST',
			timeout: 30000,
			data:    $.extend( { action: action, nonce: wpaiimageBilling.nonce }, data ),
			success: function ( resp ) {
				if ( resp && resp.success ) {
					onSuccess( resp.data || {} );
				} else {
					var msg = ( resp && resp.data && resp.data.message ) ? resp.data.message : 'An error occurred.';
					onError( msg );
				}
			},
			error: function () {
				onError( 'Connection error. Please try again.' );
			},
			complete: function () {
				stopLoadingCursor();
			},
		} );
	}

	// -------------------------------------------------------------------------
	// Subscribe / switch plan buttons
	// -------------------------------------------------------------------------

	$( document ).on( 'click', '.wpaiimage-subscribe-btn', function () {
		var plan = $( this ).data( 'plan' );
		var $btn = $( this );

		$btn.prop( 'disabled', true ).text( 'Redirecting to Stripe\u2026' );

		billingAjax(
			'wpaiimage_checkout',
			{ plan: plan },
			function ( data ) {
				window.location.href = data.url;
			},
			function ( msg ) {
				// eslint-disable-next-line no-alert
				alert( msg );
				$btn.prop( 'disabled', false ).text( 'Subscribe' );
			}
		);
	} );

	// -------------------------------------------------------------------------
	// Manage subscription (Stripe Customer Portal)
	// -------------------------------------------------------------------------

	$( '#wpaiimage-portal-btn' ).on( 'click', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( 'Loading\u2026' );

		billingAjax(
			'wpaiimage_portal',
			{},
			function ( data ) {
				window.location.href = data.url;
			},
			function ( msg ) {
				// eslint-disable-next-line no-alert
				alert( msg );
				$btn.prop( 'disabled', false ).text( 'Manage Subscription \u2192' );
			}
		);
	} );

	// -------------------------------------------------------------------------
	// Sync subscription status
	// -------------------------------------------------------------------------

	$( '#wpaiimage-sync-btn' ).on( 'click', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( 'Syncing\u2026' );

		billingAjax(
			'wpaiimage_billing_sync',
			{},
			function () {
				window.location.reload();
			},
			function ( msg ) {
				// eslint-disable-next-line no-alert
				alert( msg );
				$btn.prop( 'disabled', false ).text( 'Sync Status' );
			}
		);
	} );

	// -------------------------------------------------------------------------
	// Save BYOK API key
	// -------------------------------------------------------------------------

	$( '#wpaiimage-byok-save' ).on( 'click', function () {
		var key  = $.trim( $( '#wpaiimage-byok-key' ).val() );
		var $msg = $( '#wpaiimage-byok-msg' );
		var $btn = $( this );

		if ( ! key ) {
			$msg.html( '<span style="color:#dc3232;">Please enter an API key.</span>' );
			return;
		}

		$btn.prop( 'disabled', true );
		$msg.html( '<span style="color:#555;">Saving\u2026</span>' );

		billingAjax(
			'wpaiimage_save_byok',
			{ api_key: key },
			function ( data ) {
				$msg.html( '<span style="color:#46b450;">\u2713 ' + escHtml( data.message ) + '</span>' );
				$btn.prop( 'disabled', false );
				// Reload after short delay so the mode badge updates
				setTimeout( function () { window.location.reload(); }, 1400 );
			},
			function ( msg ) {
				$msg.html( '<span style="color:#dc3232;">' + escHtml( msg ) + '</span>' );
				$btn.prop( 'disabled', false );
			}
		);
	} );

	$( '#wpaiimage-switch-trial' ).on( 'click', function () {
		var $btn = $( this );
		var $msg = $( '#wpaiimage-byok-msg' );
		$btn.prop( 'disabled', true );
		$msg.html( '<span style="color:#555;">Switching\u2026</span>' );

		billingAjax(
			'wpaiimage_switch_trial',
			{},
			function ( data ) {
				$msg.html( '<span style="color:#46b450;">\u2713 ' + escHtml( data.message ) + '</span>' );
				setTimeout( function () { window.location.reload(); }, 800 );
			},
			function ( msg ) {
				$msg.html( '<span style="color:#dc3232;">' + escHtml( msg ) + '</span>' );
				$btn.prop( 'disabled', false );
			}
		);
	} );

	// -------------------------------------------------------------------------
	// Utility
	// -------------------------------------------------------------------------

	function escHtml( str ) {
		return $( '<div>' ).text( String( str ) ).html();
	}

} )( jQuery );
