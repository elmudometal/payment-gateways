<?php

it('will not use debugging functions')
    ->expect(['dd', 'dump', 'ray'])
    ->each->not->toBeUsed();

it('value objects are readonly')
    ->expect('Arca\PaymentGateways\ValueObjects')
    ->classes()
    ->toBeFinal()
    ->toBeReadonly();

it('enums are backed by strings')
    ->expect('Arca\PaymentGateways\Enums')
    ->enums()
    ->toBeStringBackedEnum();

it('exceptions extend GatewayException')
    ->expect('Arca\PaymentGateways\Exceptions')
    ->classes()
    ->toExtend('Arca\PaymentGateways\Exceptions\GatewayException');

it('drivers implement PaymentGateway contract')
    ->expect('Arca\PaymentGateways\Drivers')
    ->classes()
    ->toImplement('Arca\PaymentGateways\Contracts\PaymentGateway');

it('events use Dispatchable trait')
    ->expect('Arca\PaymentGateways\Events')
    ->classes()
    ->toUseTrait('Illuminate\Foundation\Events\Dispatchable');

it('contracts are interfaces')
    ->expect('Arca\PaymentGateways\Contracts')
    ->toBeInterfaces();

it('drivers build on AbstractDriver')
    ->expect('Arca\PaymentGateways\Drivers')
    ->classes()
    ->toExtend('Arca\PaymentGateways\Drivers\AbstractDriver')
    ->ignoring('Arca\PaymentGateways\Drivers\AbstractDriver');

it('leaves transactions and events to the payment service')
    ->expect('Arca\PaymentGateways\Drivers')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Support\Facades\Event']);
