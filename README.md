# Neat2FA Handler

A WordPress plugin for email-based account confirmation and admin two-factor enforcement. It adds verification for registration and checkout, plus integration with the Two Factor plugin for protected admin roles.

---

## Features

- **Registration email confirmation** - require a code before a user can create an account
- **Checkout email confirmation** - require a code before order placement in classic checkout and WooCommerce Blocks checkout
- **Guest checkout protection** - require a fresh billing email confirmation for every guest checkout
- **Timed checkout window** - let logged-in customers reuse checkout confirmation for a configurable number of hours
- **Registration password confirmation** - require customers to type the password twice before the email-code step
- **Email-based password recovery** - replace logged-in account password changes with the lost-password email flow
- **Admin 2FA enforcement** - require Two Factor for selected roles
- **Two Factor integration** - supports authenticator app, email code, and backup code providers
- **HTML email templates** - edit styled registration and checkout email templates from the settings page
- **Customizable messaging** - edit email subject lines and HTML body content from the settings page
- **Separate registration verification page** - users submit registration once, confirm the emailed code on the next page, then the account is created
- **Checkout verification modal** - customers submit checkout once to receive a code, then confirm it in a modal before the order can be created

---

## Requirements

| Requirement | Minimum |
|---|---|
| WordPress | 6.0 |
| PHP | 7.4 |
| WooCommerce | Optional, but recommended for checkout confirmation |
| Two Factor plugin | Required for admin 2FA enforcement |

---

## Installation

1. Upload the `neat-2fa-handler` folder to `/wp-content/custom-plugins/` or your local custom plugin directory
2. Activate the plugin through **Plugins -> Installed Plugins**
3. Open **WooCommerce -> Neat2FA Handler** if WooCommerce is active, or **Settings -> Neat2FA Handler** otherwise
4. Configure registration, checkout window, email templates, and admin 2FA settings
5. Save the settings

---

## Managing settings

Go to:

```text
WooCommerce -> Neat2FA Handler
```

or, if WooCommerce is not active:

```text
Settings -> Neat2FA Handler
```

The main settings cover:

1. **Registration confirmation** - turn email verification on or off for new accounts
2. **Checkout confirmation** - always require a code before order submission
3. **Checkout window** - choose how long logged-in customer checkout confirmation stays valid; use `0` to require a fresh code every time
4. **Code controls** - set code length, expiry, resend cooldown, and max attempts
5. **Email templates** - edit the subject and HTML body for registration and checkout messages
6. **Account password flow** - require password confirmation on registration and route password changes through email recovery
7. **Admin 2FA** - choose which roles must use Two Factor and which providers are allowed

---

## Email confirmation flow

The plugin supports two public-facing confirmation flows:

- **Registration** - users confirm their email address before account creation
- **Checkout** - users confirm their billing email before placing an order

Registration is handled as a two-step flow. On the first registration submit, the plugin stores the pending registration briefly, sends a code, and redirects to a verification page. The account is created only after the code is accepted.

When WooCommerce customer passwords are typed during registration, the plugin adds a confirm-password field and validates the match server-side before sending the email verification code.

Checkout is blocked server-side. Classic checkout is protected through WooCommerce validation hooks. WooCommerce Blocks checkout is protected through the Store API checkout request, so an order cannot be created by bypassing the visible field.

On the first checkout submit, the plugin sends a code and blocks order creation. The checkout page opens a verification modal when the server requires confirmation. Submitting the correct code continues checkout. Logged-in customers inside the configured checkout confirmation window are allowed by the server and do not see the modal.

Email bodies support HTML, so the default templates include styled sections, clear verification-code formatting, and fallback-friendly inline styles.

Guest checkout uses the billing email from the checkout form and always requires a fresh confirmation code. Logged-in customers can reuse checkout confirmation only within the configured checkout confirmation window; setting the window to `0` requires a fresh code every time. If the customer changes the billing email after confirming, the new email must be confirmed before the order can be placed.

Placeholders available in email bodies:

```text
{site_name}, {email}, {user_login}, {code}, {ttl_minutes}, {checkout_valid_hours}
```

---

## Admin two-factor

Neat2FA Handler integrates with the Two Factor plugin to control admin login protection for selected roles.

Supported provider labels include:

- Authenticator app (TOTP)
- Email code
- Backup codes

If the Two Factor plugin is not active, the plugin still handles registration and checkout confirmation, but admin 2FA enforcement is unavailable.

---

## Account Password Flow

WooCommerce already provides the lost-password email flow at the My Account lost-password endpoint. The plugin keeps these WooCommerce options aligned when WooCommerce is active:

```text
woocommerce_registration_generate_username=yes
woocommerce_registration_generate_password=no
woocommerce_myaccount_lost_password_endpoint=lost-password
```

WooCommerce does not provide a built-in setting to require password confirmation on registration or to disable logged-in account password changes. Neat2FA Handler adds those behaviors in code. The visible password-change fieldset is removed from the account details page, and password changes submitted manually through that form are blocked server-side.
