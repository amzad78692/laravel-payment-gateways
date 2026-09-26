# Changelog

All notable changes to `laravel-payment-gateways` will be documented in this file.

## Unreleased

- Added the `tap` driver for Tap Payments (hosted payment page via Charges API v2).
- `AbstractGateway` now provides shared helpers for drivers: HTTP sending and error handling, currency rounding, name and phone splitting, and payment page language.
