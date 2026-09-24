<?php

use Amzad\PaymentGateways\Http\Controllers\PaymentCallbackController;
use Illuminate\Support\Facades\Route;

Route::match(['get', 'post'], '{gateway}/callback', PaymentCallbackController::class)
    ->name('payment-gateways.callback');
