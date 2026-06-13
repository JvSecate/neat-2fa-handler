# Neat2FA Handler

Email confirmation for WooCommerce registration and checkout, plus admin Two Factor controls.

## Features

- Email-code confirmation before account registration.
- Password confirmation on the WooCommerce registration form.
- Email-code confirmation before WooCommerce can create an order.
- Fresh confirmation for every guest checkout.
- Optional checkout confirmation window for logged-in customers.
- Password changes through WooCommerce lost-password recovery.
- Two Factor provider enforcement for selected admin roles.

## Settings

Open **WooCommerce -> Neat2FA Handler**.

If WooCommerce is inactive, use **Settings -> Neat2FA Handler**.

Checkout confirmation has no disable option. Guests always confirm. Logged-in customers skip the modal only while inside the configured confirmation window.

## Real Blocks

- Registration creates the account only after the code is accepted.
- Classic checkout is blocked through WooCommerce checkout validation.
- WooCommerce Blocks checkout is blocked through the Store API before order creation.
- Account password changes posted manually from My Account are redirected to lost-password recovery.

The visible fields and modal are helpers only. The server-side checks are the block.

## Theme Text

Default front-end strings are intentionally short. A theme can replace them:

```php
add_filter( 'neat_2fa_handler_frontend_texts', function ( $texts ) {
	$texts['checkoutTitle']  = 'Confirmar e-mail';
	$texts['checkoutHelp']   = 'Digite o codigo enviado por e-mail.';
	$texts['checkoutLabel']  = 'Codigo';
	$texts['checkoutVerify'] = 'Continuar';
	$texts['recoveryTitle']  = 'Recuperar senha';
	$texts['recoveryHelp']   = '';
	$texts['recoveryLabel']  = 'Recuperar senha';

	return $texts;
} );
```

If the theme renders its own password recovery panel, disable the plugin panel:

```php
add_filter( 'neat_2fa_handler_render_password_recovery_panel', '__return_false' );
```

## Theme Styling

Useful selectors:

- `.asw-verification-page`
- `.asw-password-confirm-row`
- `.asw-password-recovery-panel`
- `.asw-modal.asw-modal--checkout`
- `.asw-modal__overlay`
- `.asw-modal__dialog`
- `.asw-modal__notice`
- `.asw-checkout-verify`
- `#asw-checkout-modal-code`

Useful variables:

```css
:root {
	--asw-radius: 0.5rem;
	--asw-surface: #fff;
	--asw-text: #111;
	--asw-notice-color: #b00020;
	--asw-modal-width: 28rem;
	--asw-modal-overlay: rgba(0, 0, 0, 0.55);
	--asw-content-width: 36rem;
}
```

## Email Templates

Email bodies support HTML and these placeholders:

```text
{site_name}
{email}
{user_login}
{code}
{ttl_minutes}
{checkout_valid_hours}
```

Email templates use inline styles because email clients do not load the active theme CSS.
