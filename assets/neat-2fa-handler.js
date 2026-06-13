( function () {
	'use strict';

	var config = window.Neat2FAHandler || {};
	var headerName = config.checkoutHeader || 'X-Neat-2FA-Code';
	var pendingCheckoutCode = '';
	var lastCheckoutButton = null;

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

	function sanitizeCode( value ) {
		return ( value || '' ).replace( /[^0-9]/g, '' );
	}

	function hiddenClassicCodeField() {
		return document.getElementById( 'asw-checkout-code' );
	}

	function hasCheckoutSurface() {
		return !! (
			document.querySelector( '.wp-block-woocommerce-checkout, .wc-block-checkout, form.checkout' ) ||
			document.querySelector( '.wc-block-components-checkout-place-order-button, #place_order, button[name="woocommerce_checkout_place_order"]' )
		);
	}

	function checkoutCode() {
		var field = hiddenClassicCodeField();
		return pendingCheckoutCode || ( field ? sanitizeCode( field.value ) : '' );
	}

	function checkoutButton() {
		return lastCheckoutButton ||
			document.querySelector( '.wc-block-components-checkout-place-order-button' ) ||
			document.querySelector( '.wc-block-checkout__actions_row button' ) ||
			document.querySelector( '#place_order' ) ||
			document.querySelector( 'button[name="woocommerce_checkout_place_order"]' );
	}

	function ensureCheckoutModal() {
		var existing = document.getElementById( 'asw-checkout-modal' );
		if ( existing ) {
			return existing;
		}

		var modal = document.createElement( 'div' );
		modal.className = 'asw-modal asw-modal--checkout';
		modal.id = 'asw-checkout-modal';
		modal.hidden = true;
		modal.innerHTML =
			'<div class="asw-modal__overlay" data-asw-close></div>' +
			'<div class="asw-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="asw-checkout-modal-title">' +
				'<button type="button" class="asw-modal__close" data-asw-close aria-label=""></button>' +
				'<h2 id="asw-checkout-modal-title"></h2>' +
				'<p class="asw-modal__message"></p>' +
				'<p class="asw-modal__notice" aria-live="polite"></p>' +
				'<p class="form-row form-row-wide">' +
					'<label for="asw-checkout-modal-code"></label>' +
					'<input id="asw-checkout-modal-code" class="input-text" type="text" inputmode="numeric" pattern="[0-9 ]*" autocomplete="one-time-code" value="">' +
				'</p>' +
				'<p class="asw-modal__actions">' +
					'<button type="button" class="button asw-checkout-verify"></button>' +
				'</p>' +
			'</div>';

		modal.querySelector( '.asw-modal__close' ).textContent = 'x';
		modal.querySelector( '.asw-modal__close' ).setAttribute( 'aria-label', config.checkoutClose || 'Close' );
		modal.querySelector( 'h2' ).textContent = config.checkoutTitle || 'Confirm email';
		modal.querySelector( '.asw-modal__message' ).textContent = config.checkoutHelp || 'Enter the email code.';
		modal.querySelector( 'label' ).textContent = config.checkoutLabel || 'Code';
		modal.querySelector( '.asw-checkout-verify' ).textContent = config.checkoutVerify || 'Continue';

		document.body.appendChild( modal );
		return modal;
	}

	function openCheckoutModal( message ) {
		var modal = ensureCheckoutModal();
		var notice = modal.querySelector( '.asw-modal__notice' );
		var input = modal.querySelector( '#asw-checkout-modal-code' );

		notice.textContent = message || '';
		input.value = pendingCheckoutCode || '';
		modal.hidden = false;
		window.setTimeout( function () {
			input.focus();
		}, 30 );
	}

	function closeCheckoutModal() {
		var modal = document.getElementById( 'asw-checkout-modal' );
		if ( modal ) {
			modal.hidden = true;
		}
	}

	function continueCheckoutFromModal() {
		var modal = ensureCheckoutModal();
		var input = modal.querySelector( '#asw-checkout-modal-code' );
		var code = sanitizeCode( input.value );
		var hiddenField = hiddenClassicCodeField();

		if ( ! code ) {
			modal.querySelector( '.asw-modal__notice' ).textContent = config.checkoutLabel || 'Code';
			input.focus();
			return;
		}

		pendingCheckoutCode = code;
		if ( hiddenField ) {
			hiddenField.value = code;
		}

		closeCheckoutModal();

		var button = checkoutButton();
		if ( button ) {
			button.click();
		}
	}

	function isNeatCheckoutError( data ) {
		return data && 'string' === typeof data.code && 0 === data.code.indexOf( 'asw_' );
	}

	function maybeOpenModalFromResponse( response ) {
		if ( ! response || response.ok ) {
			return;
		}

		response.clone().json().then( function ( data ) {
			if ( isNeatCheckoutError( data ) ) {
				openCheckoutModal( data.message || '' );
			}
		} ).catch( function () {} );
	}

	function initFetchGuard() {
		if ( ! window.fetch || window.fetch.__neat2faGuard ) {
			return;
		}

		var originalFetch = window.fetch;
		var guardedFetch = function ( input, init ) {
			var isCheckout = isCheckoutRequest( input );
			var code = isCheckout ? checkoutCode() : '';
			var args = arguments;

			if ( isCheckout && code ) {
				var nextInit = Object.assign( {}, init || {} );
				var headers = new Headers( nextInit.headers || ( input && input.headers ) || {} );
				headers.set( headerName, code );
				nextInit.headers = headers;
				args = [ input, nextInit ];
			}

			return originalFetch.apply( this, args ).then( function ( response ) {
				if ( isCheckout ) {
					maybeOpenModalFromResponse( response );
				}

				return response;
			} );
		};

		guardedFetch.__neat2faGuard = true;
		window.fetch = guardedFetch;
	}

	function watchCheckoutButtons() {
		document.addEventListener( 'click', function ( event ) {
			var button = event.target.closest( '.wc-block-components-checkout-place-order-button, .wc-block-checkout__actions_row button, #place_order, button[name="woocommerce_checkout_place_order"]' );
			if ( button ) {
				lastCheckoutButton = button;
			}
		}, true );
	}

	function watchClassicCheckoutErrors() {
		document.addEventListener( 'click', function ( event ) {
			var close = event.target.closest( '[data-asw-close]' );
			if ( close ) {
				closeCheckoutModal();
			}

			var verify = event.target.closest( '.asw-checkout-verify' );
			if ( verify ) {
				continueCheckoutFromModal();
			}
		} );

		document.addEventListener( 'keydown', function ( event ) {
			if ( 'Enter' === event.key && event.target && 'asw-checkout-modal-code' === event.target.id ) {
				event.preventDefault();
				continueCheckoutFromModal();
			}
		} );

		if ( window.jQuery ) {
			window.jQuery( document.body ).on( 'checkout_error', function () {
				var message = document.querySelector( '.woocommerce-error, .woocommerce-NoticeGroup-checkout .woocommerce-error' );
				if ( message && /verification code|billing email|codigo|verify|verifica/i.test( message.textContent ) ) {
					openCheckoutModal( message.textContent.trim() );
				}
			} );
		}
	}

	function accountPasswordFieldset() {
		var input = document.querySelector( 'input[name="password_current"], input[name="password_1"], input[name="password_2"]' );
		return input ? input.closest( 'fieldset' ) : null;
	}

	function ensureRecoveryPanel( fieldset ) {
		var form = document.querySelector( 'form.woocommerce-EditAccountForm, form.edit-account' );
		if ( ! form || form.querySelector( '.asw-password-recovery-panel' ) ) {
			return;
		}

		var panel = document.createElement( 'div' );
		panel.className = 'asw-password-recovery-panel';
		panel.innerHTML =
			'<h3></h3>' +
			'<p></p>' +
			'<p><a class="button" href=""></a></p>';

		panel.querySelector( 'h3' ).textContent = config.recoveryTitle || 'Recover password';
		panel.querySelector( 'p' ).textContent = config.recoveryHelp || '';
		panel.querySelector( 'a' ).textContent = config.recoveryLabel || 'Recover password';
		panel.querySelector( 'a' ).href = config.recoveryUrl || '/my-account/lost-password/';
		if ( ! panel.querySelector( 'p' ).textContent ) {
			panel.querySelector( 'p' ).remove();
		}

		if ( fieldset && fieldset.parentNode ) {
			fieldset.parentNode.insertBefore( panel, fieldset );
			return;
		}

		var submit = form.querySelector( 'button[type="submit"], input[type="submit"]' );
		if ( submit && submit.parentNode ) {
			submit.parentNode.insertBefore( panel, submit );
			return;
		}

		form.appendChild( panel );
	}

	function removeAccountPasswordFields() {
		var fieldset = accountPasswordFieldset();
		if ( ! fieldset ) {
			return;
		}

		ensureRecoveryPanel( fieldset );
		fieldset.remove();
	}

	function init() {
		if ( hasCheckoutSurface() ) {
			initFetchGuard();
			watchCheckoutButtons();
			watchClassicCheckoutErrors();
		}

		removeAccountPasswordFields();

		var accountForm = document.querySelector( 'form.woocommerce-EditAccountForm, form.edit-account' );
		if ( accountForm && window.MutationObserver ) {
			new MutationObserver( removeAccountPasswordFields ).observe( accountForm, { childList: true, subtree: true } );
		}
	}

	if ( 'loading' === document.readyState ) {
		document.addEventListener( 'DOMContentLoaded', init );
	} else {
		init();
	}
}() );
