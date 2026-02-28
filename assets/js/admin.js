/* global wpaiimage, jQuery */
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
	// Bulk generate page
	// -------------------------------------------------------------------------

	if ( $( '#wpaiimage-posts-table' ).length ) {

		// Header checkbox — toggle all
		$( '#wpaiimage-check-all' ).on( 'change', function () {
			$( '.wpaiimage-post-checkbox' ).prop( 'checked', $( this ).is( ':checked' ) );
		} );

		$( '#wpaiimage-select-all' ).on( 'click', function () {
			$( '.wpaiimage-post-checkbox' ).prop( 'checked', true );
			$( '#wpaiimage-check-all' ).prop( 'checked', true );
		} );

		$( '#wpaiimage-select-none' ).on( 'click', function () {
			$( '.wpaiimage-post-checkbox' ).prop( 'checked', false );
			$( '#wpaiimage-check-all' ).prop( 'checked', false );
		} );

		// Select only posts without a featured image
		$( '#wpaiimage-select-missing' ).on( 'click', function () {
			$( '.wpaiimage-post-checkbox' ).each( function () {
				var row = $( this ).closest( 'tr' );
				$( this ).prop( 'checked', row.data( 'has-thumbnail' ) !== '1' && row.data( 'has-thumbnail' ) !== 1 );
			} );
			updateCheckAll();
		} );

		// Bulk generate
		$( '#wpaiimage-generate-selected' ).on( 'click', function () {
			var ids = [];
			$( '.wpaiimage-post-checkbox:checked' ).each( function () {
				ids.push( $( this ).val() );
			} );
			if ( ! ids.length ) {
				// eslint-disable-next-line no-alert
				alert( wpaiimage.i18n.select_one );
				return;
			}
			generateBatch( ids );
		} );

		// Single-row generate / regenerate
		$( document ).on( 'click', '.wpaiimage-generate-single', function () {
			var postId    = $( this ).data( 'post-id' );
			var row       = $( '#wpaiimage-row-' + postId );
			var hasThumb  = row.data( 'has-thumbnail' ) === '1' || row.data( 'has-thumbnail' ) === 1;

			if ( hasThumb && ! window.confirm( wpaiimage.i18n.confirm_overwrite ) ) { // eslint-disable-line no-alert
				return;
			}
			generateSingle( postId, $( this ) );
		} );
	}

	// -------------------------------------------------------------------------
	// Post editor metabox
	// -------------------------------------------------------------------------

	$( '#wpaiimage-metabox-generate' ).on( 'click', function () {
		var postId   = $( this ).data( 'post-id' );
		var hasThumb = $( this ).data( 'has-thumbnail' ) === '1' || $( this ).data( 'has-thumbnail' ) === 1;

		if ( hasThumb && ! window.confirm( wpaiimage.i18n.confirm_overwrite ) ) { // eslint-disable-line no-alert
			return;
		}

		var $btn    = $( this );
		var $status = $( '#wpaiimage-metabox-status' );

		$btn.prop( 'disabled', true );
		$status.html( '<span style="color:#0073aa;">' + wpaiimage.i18n.generating + '</span>' );
		startLoadingCursor();

		$.ajax( {
			url:     wpaiimage.ajax_url,
			type:    'POST',
			timeout: 120000,
			data: {
				action:  'wpaiimage_generate',
				nonce:   wpaiimage.nonce,
				post_id: postId,
			},
			success: function ( response ) {
				$btn.prop( 'disabled', false );
				if ( response.success ) {
					$btn.text( wpaiimage.i18n.regenerate );
					$btn.data( 'has-thumbnail', '1' );
					$status.html( '<span style="color:#46b450;">&#10003; ' + wpaiimage.i18n.refresh_notice + '</span>' );
				} else {
					var msg = ( response.data && response.data.message ) ? response.data.message : wpaiimage.i18n.failed;
					window.alert( msg );
					$status.html( '<span style="color:#dc3232;">&#10007; ' + escHtml( msg ) + '</span>' );
				}
			},
			error: function () {
				$btn.prop( 'disabled', false );
				$status.html( '<span style="color:#dc3232;">&#10007; ' + wpaiimage.i18n.failed + '</span>' );
			},
			complete: function () {
				stopLoadingCursor();
			},
		} );
	} );

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	function generateSingle( postId, $btn ) {
		$btn.prop( 'disabled', true ).html( wpaiimage.i18n.generating );
		setStatusHtml( postId, '<span style="color:#0073aa;">&#9679; ' + wpaiimage.i18n.generating + '</span>' );
		startLoadingCursor();

		$.ajax( {
			url:     wpaiimage.ajax_url,
			type:    'POST',
			timeout: 120000,
			data: {
				action:  'wpaiimage_generate',
				nonce:   wpaiimage.nonce,
				post_id: postId,
			},
			success: function ( response ) {
				$btn.prop( 'disabled', false );
				var row = $( '#wpaiimage-row-' + postId );

				if ( response.success ) {
					setStatusHtml( postId, '<span style="color:#46b450;">&#10003; ' + wpaiimage.i18n.done + '</span>' );
					if ( response.data && response.data.thumbnail_html ) {
						row.find( '.wpaiimage-thumbnail-cell' ).html( response.data.thumbnail_html );
						row.data( 'has-thumbnail', '1' );
					}
					$btn.text( wpaiimage.i18n.regenerate );
				} else {
					var msg = ( response.data && response.data.message ) ? response.data.message : wpaiimage.i18n.failed;
					window.alert( msg );
					setStatusHtml( postId, '<span style="color:#dc3232;" title="' + escAttr( msg ) + '">&#10007; ' + wpaiimage.i18n.failed + '</span>' );
					$btn.text( wpaiimage.i18n.generate );
				}
			},
			error: function () {
				$btn.prop( 'disabled', false ).text( wpaiimage.i18n.generate );
				setStatusHtml( postId, '<span style="color:#dc3232;">&#10007; ' + wpaiimage.i18n.failed + '</span>' );
			},
			complete: function () {
				stopLoadingCursor();
			},
		} );
	}

	function generateBatch( ids ) {
		var total    = ids.length;
		var done     = 0;
		var $genBtn  = $( '#wpaiimage-generate-selected' );
		var $progBar = $( '#wpaiimage-progress-bar-wrap' );
		var $fill    = $( '#wpaiimage-progress-fill' );
		var $text    = $( '#wpaiimage-progress-text' );

		$genBtn.prop( 'disabled', true );
		$progBar.show();
		$fill.css( 'width', '0%' );
		startLoadingCursor();

		function processNext( index ) {
			if ( index >= ids.length ) {
				$fill.css( 'width', '100%' );
				$text.html( wpaiimage.i18n.complete.replace( '%d', done ) );
				$genBtn.prop( 'disabled', false );
				stopLoadingCursor();
				return;
			}

			var postId = ids[ index ];
			var $btn   = $( '#wpaiimage-row-' + postId ).find( '.wpaiimage-generate-single' );
			var pct    = Math.round( ( index / total ) * 100 );

			$fill.css( 'width', pct + '%' );
			$text.html(
				wpaiimage.i18n.progress
					.replace( '%1$d', index + 1 )
					.replace( '%2$d', total )
			);
			$btn.prop( 'disabled', true ).html( wpaiimage.i18n.generating );
			setStatusHtml( postId, '<span style="color:#0073aa;">&#9679; ' + wpaiimage.i18n.generating + '</span>' );

			$.ajax( {
				url:     wpaiimage.ajax_url,
				type:    'POST',

				timeout: 120000,
				data: {
					action:  'wpaiimage_generate',
					nonce:   wpaiimage.nonce,
					post_id: postId,
				},
				success: function ( response ) {
					var row = $( '#wpaiimage-row-' + postId );

					if ( response.success ) {
						done++;
						setStatusHtml( postId, '<span style="color:#46b450;">&#10003; ' + wpaiimage.i18n.done + '</span>' );
						if ( response.data && response.data.thumbnail_html ) {
							row.find( '.wpaiimage-thumbnail-cell' ).html( response.data.thumbnail_html );
							row.data( 'has-thumbnail', '1' );
						}
						$btn.prop( 'disabled', false ).text( wpaiimage.i18n.regenerate );
					} else {
						var msg = ( response.data && response.data.message ) ? response.data.message : wpaiimage.i18n.failed;
						window.alert( msg );
						setStatusHtml( postId, '<span style="color:#dc3232;" title="' + escAttr( msg ) + '">&#10007; ' + wpaiimage.i18n.failed + '</span>' );
						$btn.prop( 'disabled', false ).text( wpaiimage.i18n.generate );
					}
					processNext( index + 1 );
				},
				error: function () {
					setStatusHtml( postId, '<span style="color:#dc3232;">&#10007; ' + wpaiimage.i18n.failed + '</span>' );
					$btn.prop( 'disabled', false ).text( wpaiimage.i18n.generate );
					processNext( index + 1 );
				},
			} );
		}

		processNext( 0 );
	}

	function setStatusHtml( postId, html ) {
		$( '#wpaiimage-status-' + postId ).html( html );
	}

	function escHtml( str ) {
		return $( '<div>' ).text( String( str ) ).html();
	}

	function escAttr( str ) {
		return escHtml( str ).replace( /"/g, '&quot;' );
	}

	function updateCheckAll() {
		var total   = $( '.wpaiimage-post-checkbox' ).length;
		var checked = $( '.wpaiimage-post-checkbox:checked' ).length;
		$( '#wpaiimage-check-all' ).prop( 'checked', total > 0 && checked === total );
	}

} )( jQuery );
