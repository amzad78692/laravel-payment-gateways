<?php

namespace Amzad\PaymentGateways;

use Illuminate\Support\Facades\Route;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class PaymentGatewaysServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-payment-gateways')
            ->hasConfigFile();
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(PaymentGateways::class);
        $this->app->alias(PaymentGateways::class, 'payment-gateways');
    }

    public function packageBooted(): void
    {
        if (! config('payment-gateways.routes.enabled', true)) {
            return;
        }

        Route::prefix(config('payment-gateways.routes.prefix', 'payment-gateways'))
            ->middleware(config('payment-gateways.routes.middleware', ['api']))
            ->group(__DIR__.'/../routes/payment-gateways.php');
    }
}
