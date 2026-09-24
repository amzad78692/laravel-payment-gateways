<?php

arch('it will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

arch('contracts are interfaces')
    ->expect('Amzad\PaymentGateways\Contracts')
    ->toBeInterfaces();

arch('enums are enums')
    ->expect('Amzad\PaymentGateways\Enums')
    ->toBeEnums();

arch('gateways implement the contract')
    ->expect('Amzad\PaymentGateways\Gateways')
    ->toImplement('Amzad\PaymentGateways\Contracts\Gateway');
