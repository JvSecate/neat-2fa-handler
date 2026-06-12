( function () {
	'use strict';

	var config = window.Neat2FAHandler || {};
	var headerName = config.checkoutHeader || 'X-Neat-2FA-Code';

	function checkoutRoot() {
		return document.querySelector( '.wp-block-woocommerce-checkout, .wc-block-checkout' );
	}

	function currentCodeInput() {
		return document.getElementById( 'asw-block-checkout-code' ) || document.getElementById( 'asw-checkout-code' );
	}

	function checkoutRequestUrl( input ) {
		if ( 'string' === typeof input ) {
			return input;
		}

		if ( input && 'string' === typeof input.url ) {
			return input.url;
		}

		return '';
	}

	function isCheckoutRequest( input ) {
		return /\/wc\/store\/v1\/checkout(?:\?|$)/.test( checkoutRequestUrl( input ) );
	}

	function mountCheckoutCodeField() {
		var root = checkoutRoot();
		if ( ! root || currentCodeInput() ) {
			return;
		}

		var panel = document.createElement( 'div' );
		panel.className = 'asw-confirmation-step asw-checkout-confirmation';
		panel.id = 'asw-block-checkout-confirmation';
		panel.innerHTML =
			'<h3></h3>' +
			'<p></p>' +
			'<p class="form-row form-row-wide">' +
				'<label for="asw-block-checkout-code"></label>' +
				'<input id="asw-block-checkout-code" class="input-text" type="text" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" value="">' +
			'</p>';

		panel.querySelector( 'h3' ).textContent = config.checkoutTitle || 'Billing email verification';
		panel.querySelector( 'p' ).textContent = config.checkoutHelp || 'Click Place Order once to receive a verification code. Then enter the code here and click Place Order again.';
		panel.querySelector( 'label' ).textContent = config.checkoutLabel || 'Verification code';

		var anchor = document.querySelector( '[data-block-name="woocommerce/checkout-contact-information-block"]' ) ||
			document.querySelector( '.wc-block-components-address-form' ) ||
			root.firstElementChild;

		if ( anchor && anchor.parentNode ) {
			anchor.parentNode.insertBefore( panel, anchor.nextSibling );
			return;
		}

		root.insertBefore( panel, root.firstChild );
	}

	function checkoutCode() {
		var input = currentCodeInput();
		return input ? input.value.replace( /[^0-9]/g, '' ) : '';
	}

	function initFetchGuard() {
		if ( ! window.fetch || window.fetch.__neat2faGuard ) {
			return;
		}

		var originalFetch = window.fetch;
		var guardedFetch = function ( input, init ) {
			var code = checkoutCode();
			if ( ! code || ! isCheckoutRequest( input ) ) {
				return originalFetch.apply( this, arguments );
			}

			var nextInit = Object.assign( {}, init || {} );
			var headers = new Headers( nextInit.headers || ( input && input.headers ) || {} );
			headers.set( headerName, code );
			nextInit.headers = headers;

			return originalFetch.call( this, input, nextInit );
		};

		guardedFetch.__neat2faGuard = true;
		window.fetch = guardedFetch;
	}

	function init() {
		mountCheckoutCodeField();
		initFetchGuard();

		var root = checkoutRoot();
		if ( root && window.MutationObserver ) {
			new MutationObserver( mountCheckoutCodeField ).observe( root, { childList: true, subtree: true } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
