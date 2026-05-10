## CrazzyPe Payment Gateway for WHMCS

Integrate CrazzyPe payment gateway with WHMCS to accept payments securely.

### What's New in Version 2.0

- **SignUp & Login Added**: Users can now sign up and log in directly through the CrazzyPe module interface.
- **Webhook URL Added**: Simplified webhook URL configuration for seamless callback handling.
- **Payment Button Changed**: Redesigned payment button for better user experience and accessibility.
- **SQL Import Automated**: SQL database import is now automated during installation, reducing setup time.

## Features

- Easy setup and integration.
- Secure payment processing (server-side order verification, optional HMAC when `callback_secret` is set).
- Automatic invoice updates on successful payments.
- Idempotent callbacks: duplicate transaction IDs do not apply payment twice or inflate client credit.
- Handles payment failures gracefully.

## Installation

1. Copy the contents of this repository into your WHMCS installation:
   - `gateways/crazzype.php` → `modules/gateways/crazzype.php`
   - `gateways/callback/crazzype.php` → `modules/gateways/callback/crazzype.php`
   - `gateways/callback/crazzype_callback.php` → `modules/gateways/callback/crazzype_callback.php` (legacy shim; optional if you only use the canonical URL)
   - `gateways/crazzype/` (e.g. `whmcs.json`) → `modules/gateways/crazzype/`

2. Enable the CrazzyPe module in **System Settings → Payment Gateways**.

3. Enter **API Key**, **Merchant Key**, and optionally **Callback secret** (see below).

4. In the CrazzyPe merchant dashboard, set the callback URL to the **canonical** endpoint:

   `https://yourdomain.com/path/to/whmcs/modules/gateways/callback/crazzype.php`

   If you already use the legacy filename, this still works:

   `https://yourdomain.com/path/to/whmcs/modules/gateways/callback/crazzype_callback.php`

## Configuration

- **API Key**: From the CrazzyPe Merchant Dashboard.
- **Merchant Key**: Provided by CrazzyPe (e.g. paytm, phonepe).
- **Callback secret (optional)**: If CrazzyPe documents a shared secret for callback hashing, set it here. The module attempts `HMAC-SHA256(order_id|status)` and `HMAC-SHA256(order_id)`; adjust in code if their algorithm differs. If left empty, callbacks are still validated via the CrazzyPe **check-order-status** API and amount checks, but the redirect `hash` parameter is not cryptographically verified.

## Callback handling

CrazzyPe returns the customer to your callback URL with parameters such as `status`, `order_id`, and `hash`. The handler merges **GET**, **POST**, and **JSON body** (for webhooks), verifies status with CrazzyPe’s API, matches the paid amount to the invoice balance, and applies the payment once per gateway transaction ID.

Use the same transaction reference in webhooks and browser returns so duplicate notifications stay idempotent.

### Example callback URL

```
https://yourdomain.com/modules/gateways/callback/crazzype.php?status=success&order_id=12345_1234567890&hash=abc123
```

## Troubleshooting

- **Missing parameters**: Ensure `status` and `order_id` are present (and `hash` if you enabled `callback_secret`).
- **Invalid invoice ID**: Confirm the invoice exists in WHMCS; `order_id` must start with the numeric invoice ID (e.g. `12345_timestamp`).
- **Amount mismatch**: Gateway amount must match the invoice balance within a small tolerance (see gateway log).
- **Duplicate payments / client credit**: Ensure you are on a version that deduplicates by transaction ID; do not run two different callback URLs that both apply payments for the same event without shared idempotency.

## Support

Contact [support@crazzype.com](mailto:support@crazzype.com) for assistance.

## License

Licensed under the [MIT License](LICENSE).
