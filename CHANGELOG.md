# Changelog

All notable changes to `laravel-payment-gateways` will be documented in this file.

## Unreleased

- Removed customer phone numbers: `PaymentRequestData::$customerPhone`, `splitPhone()`, and the `phone_country_code` (Tap) and `mobile_country_code` (MyFatoorah) config keys. No gateway receives a phone number. Tap now requires a customer email.
- Added the `knet` driver for KNET (RAW integration with the hosted payment page; encrypted `trandata` responses, error redirects always treated as failures).
- Added `Contracts\CustomizesCallbackResponse`, letting a gateway decide how the callback route answers (KNET's server notification must get a plain-text `REDIRECT=<url>` line). Redirect targets in `payment-gateways.redirects` are now made absolute.
- Added the `hesabe` driver for Hesabe (hosted payment page via the Checkout API, callbacks verified with Transaction Enquiry).
- Added the `tap` driver for Tap Payments (hosted payment page via Charges API v2).
- `AbstractGateway` now provides shared helpers for drivers: HTTP sending and error handling, currency rounding, name splitting, and payment page language.
