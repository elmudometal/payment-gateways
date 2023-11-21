# payment gateway integration in laravel

[![Latest Version on Packagist](https://img.shields.io/packagist/v/arca/payment-gateways.svg?style=flat-square)](https://packagist.org/packages/arca/payment-gateways)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/arca/payment-gateways/run-tests.yml?branch=main&label=tests&style=flat-square)](https://github.com/arca/payment-gateways/actions?query=workflow%3Arun-tests+branch%3Amain)
[![GitHub Code Style Action Status](https://img.shields.io/github/actions/workflow/status/arca/payment-gateways/fix-php-code-style-issues.yml?branch=main&label=code%20style&style=flat-square)](https://github.com/arca/payment-gateways/actions?query=workflow%3A"Fix+PHP+code+style+issues"+branch%3Amain)
[![Total Downloads](https://img.shields.io/packagist/dt/arca/payment-gateways.svg?style=flat-square)](https://packagist.org/packages/arca/payment-gateways)

This is where your description should go. Limit it to a paragraph or two. Consider adding a small example.

## Support us

[<img src="https://github-ads.s3.eu-central-1.amazonaws.com/payment-gateways.jpg?t=1" width="419px" />](https://spatie.be/github-ad-click/payment-gateways)

We invest a lot of resources into creating [best in class open source packages](https://spatie.be/open-source). You can support us by [buying one of our paid products](https://spatie.be/open-source/support-us).

We highly appreciate you sending us a postcard from your hometown, mentioning which of our package(s) you are using. You'll find our address on [our contact page](https://spatie.be/about-us). We publish all received postcards on [our virtual postcard wall](https://spatie.be/open-source/postcards).

## Installation

You can install the package via composer:

```bash
composer require arca/payment-gateways
```

You can install the package via git:
add in composer.json section require
```bash
"arca/payment-gateways": "dev-main",
```
And repositories
```bash
"repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:elmudometal/payment-gateways.git"
        }
    ],
```

You can publish and run the migrations with:

```bash
php artisan vendor:publish --tag="payment-gateways-migrations"
php artisan migrate
```

You can publish the config file with:

```bash
php artisan vendor:publish --tag="payment-gateways-config"
```

This is the contents of the published config file:

```php
return [
    'getnet' => [
        'commerce_name' => 'Getnet Nombre de Comercio',
        'login' => env('GETNET_LOGIN', '7ffbb7bf1f7361b1200b2e8d74e1d76f'),
        'tranKey' => env('GETNET_TRAN_KEY', 'SnZP3D63n3I9dH9O'),
        'baseUrl' => env('GETNET_BASE_URL', 'https://checkout.test.getnet.cl'),
    ],
    'webpay' => [
        'commerce_name' => 'Webpay Nombre de Comercio'
    ],
    'paypal' => [
        'commerce_name' => 'Paypal Nombre de Comercio'
    ]
];
```

You can publish the assets file with:

```bash
php artisan vendor:publish --tag="payment-gateways-assets"
```

Optionally, you can publish the views using

```bash
php artisan vendor:publish --tag="payment-gateways-views"
```

## Usage

```php
TODO
```

## Testing

```bash
composer test
```

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## Security Vulnerabilities

Please review [our security policy](../../security/policy) on how to report security vulnerabilities.

## Credits

- [Hernan Soto](https://github.com/elmudometal)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
