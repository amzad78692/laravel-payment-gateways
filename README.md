# Laravel Payment Gateways

[![Latest Version on Packagist](https://img.shields.io/packagist/v/amzad/laravel-payment-gateways.svg?style=flat-square)](https://packagist.org/packages/amzad/laravel-payment-gateways)
[![Tests](https://img.shields.io/github/actions/workflow/status/amzad/laravel-payment-gateways/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/amzad/laravel-payment-gateways/actions?query=workflow%3Arun-tests+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/amzad/laravel-payment-gateways.svg?style=flat-square)](https://packagist.org/packages/amzad/laravel-payment-gateways)

Unified payment processing for Laravel with Gulf-region gateways (MyFatoorah, KNET, Tap, Hesabe, UPayment) behind a single driver-based API. New providers can be added by implementing one driver class.

> **Status:** under active development.

## Installation

```bash
composer require amzad/laravel-payment-gateways
```

Publish the config file:

```bash
php artisan vendor:publish --tag="laravel-payment-gateways-config"
```

## Usage

_Coming soon._

## Testing

```bash
composer test
```

## Changelog

See [CHANGELOG](CHANGELOG.md).

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Amzad](https://github.com/amzad)

## License

The MIT License (MIT). See [License File](LICENSE.md).
