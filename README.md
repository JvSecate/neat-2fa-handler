# Neat2FA Handler

A WordPress plugin for email-based account confirmation and admin two-factor enforcement. It adds verification for registration and checkout, plus integration with the Two Factor plugin for protected admin roles.

---

## Features

- **Registration email confirmation** - require a code before a user can create an account
- **Checkout email confirmation** - require a code before order placement
- **Timed checkout window** - let confirmed checkout emails stay valid for a configurable number of hours
- **Admin 2FA enforcement** - require Two Factor for selected roles
- **Two Factor integration** - supports authenticator app, email code, and backup code providers
- **Customizable messaging** - edit popup text, email subject lines, and email body content from the settings page
- **Frontend popup verification** - users enter the code in a modal flow without leaving the page

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
4. Configure registration, checkout, and admin 2FA settings
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
2. **Checkout confirmation** - require a code before order submission
3. **Checkout window** - choose how long checkout confirmation stays valid
4. **Code controls** - set code length, expiry, resend cooldown, and max attempts
5. **Email templates** - edit the subject and body for registration and checkout messages
6. **Popup copy** - change the title, helper text, and button labels in the modal
7. **Admin 2FA** - choose which roles must use Two Factor and which providers are allowed

---

## Email confirmation flow

The plugin supports two public-facing confirmation flows:

- **Registration** - users confirm their email address before account creation
- **Checkout** - users confirm their billing email before placing an order

The confirmation modal is rendered on the frontend and handled with AJAX.

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
