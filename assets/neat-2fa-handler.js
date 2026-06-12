( function ( $ ) {
	'use strict';

	var state = {
		context: '',
		email: '',
		trigger: null
	};

	function config() {
		return window.ASWAccountSecurity || {};
	}

	function getEmail( context ) {
		var selector = 'checkout' === context ? '#billing_email' : '#reg_email, input[name="email"], input[name="user_email"]';
		return $.trim( $( selector ).first().val() || '' );
	}

	function getTokenField( context ) {
		var name = 'checkout' === context ? 'asw_checkout_verification_token' : 'asw_registration_verification_token';
		return $( 'input[name="' + name + '"]' );
	}

	function setStatus( trigger, message, success ) {
		var $wrap = $( trigger ).closest( '.asw-email-confirmation' );
		$wrap.find( '.asw-confirmation-status' )
			.text( message || '' )
			.toggleClass( 'is-success', !! success )
			.toggleClass( 'is-error', false === success );
	}

	function openModal( context, trigger ) {
		var data = config();
		var contextData = data.contexts && data.contexts[ context ] ? data.contexts[ context ] : {};
		var email = getEmail( context );
		var colors = data.colors || {};

		if ( ! email ) {
			setStatus( trigger, data.text.emailMissing, false );
			return;
		}

		state.context = context;
		state.email = email;
		state.trigger = trigger;

		$( '#asw-code-modal' )
			.css( '--asw-background', colors.background || '#ffffff' )
			.css( '--asw-accent', colors.accent || '#1f6feb' )
			.css( '--asw-overlay', colors.overlay || '#111827' );
		$( '#asw-modal-title' ).text( contextData.title || '' );
		$( '.asw-modal__message' ).text( contextData.message || '' );
		$( '.asw-modal__email' ).text( email );
		$( '.asw-verify-code' ).text( data.text.button || 'Verify code' );
		$( '.asw-resend-code' ).text( data.text.resend || 'Send code again' );
		$( '#asw-code-input' ).val( '' ).attr( 'maxlength', data.codeSize || 8 );
		$( '.asw-modal__notice' ).removeClass( 'is-error is-success' ).text( '' );
		$( '#asw-code-modal' ).prop( 'hidden', false );
		$( '#asw-code-input' ).trigger( 'focus' );

		sendCode();
	}

	function closeModal() {
		$( '#asw-code-modal' ).prop( 'hidden', true );
	}

	function setModalNotice( message, success ) {
		$( '.asw-modal__notice' )
			.text( message || '' )
			.toggleClass( 'is-success', !! success )
			.toggleClass( 'is-error', false === success );
	}

	function post( action, extra ) {
		return $.post( config().ajaxUrl, $.extend( {
			action: action,
			nonce: config().nonce,
			context: state.context,
			email: state.email
		}, extra || {} ) );
	}

	function sendCode() {
		setModalNotice( config().text.sending, true );
		post( 'asw_send_email_code' )
			.done( function ( response ) {
				if ( response && response.success && response.data && response.data.token ) {
					getTokenField( state.context ).val( response.data.token );
					setStatus( state.trigger, response.data.message || config().text.success, true );
					setModalNotice( response.data.message || config().text.success, true );
					return;
				}
				setModalNotice( response.data && response.data.message ? response.data.message : '', true );
			} )
			.fail( function ( xhr ) {
				var message = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : config().text.error;
				setModalNotice( message, false );
			} );
	}

	function verifyCode() {
		var code = $( '#asw-code-input' ).val().replace( /[^0-9]/g, '' );
		setModalNotice( config().text.verifying, true );
		post( 'asw_verify_email_code', { code: code } )
			.done( function ( response ) {
				var message = response.data && response.data.message ? response.data.message : config().text.success;
				getTokenField( state.context ).val( response.data.token || '' );
				setStatus( state.trigger, message, true );
				setModalNotice( message, true );
				window.setTimeout( closeModal, 700 );
			} )
			.fail( function ( xhr ) {
				var message = xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : config().text.error;
				setModalNotice( message, false );
			} );
	}

	$( document ).on( 'click', '.asw-open-code-modal', function () {
		openModal( $( this ).data( 'asw-context' ), this );
	} );

	$( document ).on( 'click', '[data-asw-close]', closeModal );
	$( document ).on( 'click', '.asw-resend-code', sendCode );
	$( document ).on( 'click', '.asw-verify-code', verifyCode );
	$( document ).on( 'keydown', '#asw-code-input', function ( event ) {
		if ( 'Enter' === event.key ) {
			event.preventDefault();
			verifyCode();
		}
	} );
} )( jQuery );
