/* global picmentAiImageBilling, jQuery */
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
			url:     picmentAiImageBilling.ajax_url,
			type:    'POST',
			timeout: 30000,
			data:    $.extend( { action: action, nonce: picmentAiImageBilling.nonce }, data ),
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

	$( document ).on( 'click', '.picment-ai-image-subscribe-btn', function () {
		var plan = $( this ).data( 'plan' );
		var $btn = $( this );

		$btn.prop( 'disabled', true ).text( 'Redirecting to Stripe\u2026' );

		billingAjax(
			'picment_ai_image_checkout',
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

	$( '#picment-ai-image-portal-btn' ).on( 'click', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( 'Loading\u2026' );

		billingAjax(
			'picment_ai_image_portal',
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

	$( '#picment-ai-image-sync-btn' ).on( 'click', function () {
		var $btn = $( this );
		$btn.prop( 'disabled', true ).text( 'Syncing\u2026' );

		billingAjax(
			'picment_ai_image_billing_sync',
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

	// Toggle provider-specific fields visibility
	function toggleProviderFields() {
		var provider = $( 'input.picment-ai-image-provider-radio:checked' ).val() || 'openai';
		if ( provider === 'fal' ) {
			$( '#picment-ai-image-openai-row' ).hide();
			$( '#picment-ai-image-fal-model-row, #picment-ai-image-fal-key-row' ).show();
		} else {
			$( '#picment-ai-image-openai-row' ).show();
			$( '#picment-ai-image-fal-model-row, #picment-ai-image-fal-key-row' ).hide();
		}
	}
	$( document ).on( 'change', '.picment-ai-image-provider-radio', toggleProviderFields );
	toggleProviderFields();

	$( '#picment-ai-image-byok-save' ).on( 'click', function () {
		var provider = $( 'input.picment-ai-image-provider-radio:checked' ).val() || 'openai';
		var $msg = $( '#picment-ai-image-byok-msg' );
		var $btn = $( this );
		var postData = { provider: provider };

		if ( provider === 'openai' ) {
			var key = $.trim( $( '#picment-ai-image-byok-key' ).val() );
			if ( ! key ) {
				$msg.html( '<span style="color:#dc3232;">Please enter an OpenAI API key.</span>' );
				return;
			}
			postData.api_key = key;
		} else {
			var falKey = $.trim( $( '#picment-ai-image-fal-key' ).val() );
			if ( ! falKey ) {
				$msg.html( '<span style="color:#dc3232;">Please enter a fal.ai API key.</span>' );
				return;
			}
			postData.fal_api_key = falKey;
			postData.fal_model = $( '#picment-ai-image-fal-model' ).val();
		}

		$btn.prop( 'disabled', true );
		$msg.html( '<span style="color:#555;">Saving\u2026</span>' );

		billingAjax(
			'picment_ai_image_save_byok',
			postData,
			function ( data ) {
				$msg.html( '<span style="color:#46b450;">\u2713 ' + escHtml( data.message ) + '</span>' );
				$btn.prop( 'disabled', false );
				setTimeout( function () { window.location.reload(); }, 1400 );
			},
			function ( msg ) {
				$msg.html( '<span style="color:#dc3232;">' + escHtml( msg ) + '</span>' );
				$btn.prop( 'disabled', false );
			}
		);
	} );

	$( '#picment-ai-image-switch-trial' ).on( 'click', function () {
		var $btn = $( this );
		var $msg = $( '#picment-ai-image-byok-msg' );
		$btn.prop( 'disabled', true );
		$msg.html( '<span style="color:#555;">Switching\u2026</span>' );

		billingAjax(
			'picment_ai_image_switch_trial',
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
