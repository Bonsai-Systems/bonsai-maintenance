/**
 * Settings → Maintenance Mode: media pickers and the preview-token generator.
 * Enqueued on that screen only.
 */
( function ( $ ) {
	'use strict';

	var strings = window.cmmAdmin || {};

	$( document ).on( 'click', '.cmm-media-select', function ( e ) {
		e.preventDefault();
		var fieldName = $( this ).data( 'field' );
		var input     = $( '#' + fieldName );
		var preview   = $( '#' + fieldName + '_preview' );
		var removeBtn = $( '#' + fieldName + '_remove' );

		var frame = wp.media( {
			title: strings.mediaTitle || 'Select image',
			button: { text: strings.mediaButton || 'Use this image' },
			multiple: false
		} );

		frame.on( 'select', function () {
			var attachment = frame.state().get( 'selection' ).first().toJSON();
			input.val( attachment.url );
			preview.attr( 'src', attachment.url ).prop( 'hidden', false );
			removeBtn.prop( 'hidden', false );
		} );

		frame.open();
	} );

	$( document ).on( 'click', '.cmm-media-remove', function ( e ) {
		e.preventDefault();
		var fieldName = $( this ).data( 'field' );
		$( '#' + fieldName ).val( '' );
		$( '#' + fieldName + '_preview' ).prop( 'hidden', true ).attr( 'src', '' );
		$( this ).prop( 'hidden', true );
	} );

	// Preview token: 24 characters from the browser's CSPRNG. The token is
	// the only thing stopping strangers bypassing maintenance mode, so it
	// shouldn't come from Math.random().
	$( document ).on( 'click', '#cmm_generate_token', function ( e ) {
		e.preventDefault();
		var chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
		var bytes = new Uint32Array( 24 );
		var token = '';

		window.crypto.getRandomValues( bytes );
		for ( var i = 0; i < bytes.length; i++ ) {
			token += chars.charAt( bytes[ i ] % chars.length );
		}

		$( '#cmm_preview_token' ).val( token );
	} );

	$( document ).on( 'focus click', '.cmm-select-on-focus', function () {
		$( this ).trigger( 'select' );
	} );
} )( jQuery );
