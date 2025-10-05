# CakePHP2 AWS Plugin

[![GitHub License](https://img.shields.io/github/license/pieceofcake2/aws?label=License)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/pieceofcake2/aws?label=Packagist)](https://packagist.org/packages/pieceofcake2/aws)
![PHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/aws/php?logo=php&logoColor=%23FFFFFF&label=PHP&labelColor=%23777BB4&color=%23FFFFFF)
![CakePHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/aws/pieceofcake2/cakephp?logo=cakephp&logoColor=%23FFFFFF&label=CakePHP&labelColor=%23D33C43&color=%23FFFFFF)
[![Tests](https://img.shields.io/github/actions/workflow/status/pieceofcake2/aws/tests.yml?label=Tests)](https://github.com/pieceofcake2/aws/actions/workflows/tests.yml)
[![Codecov](https://img.shields.io/codecov/c/gh/pieceofcake2/aws?label=Coverage)](https://codecov.io/gh/pieceofcake2/aws)

## Installation

```
composer require pieceofcake2/aws
```

## Config

`Config/core.php`

```php
    Configure::write('Session', [
        'defaults' => 'php',
        'handler' => [
            'engine' => 'Aws.DynamoDbSession',
            'table_name' => 'DYNAMODB_TABLE_NAME'
        ],
        'timeout' => 1440,
        'ini' => [
            'session.cookie_lifetime' => 0,
            'session.gc_maxlifetime' => 2580000,
            'session.gc_probability' => 1,
            'session.gc_divisor' => 100,
        ],
    ]);
```

```php
/*
 * Aws
 */
    Configure::write('Aws', [
        'region' => 'ap-northeast-1',
        'version' => 'latest',
    ]);
```

`Config/email.php`

```php
class EmailConfig
{
    /**
     * Default email profile values.
     *
     * @var array
     */
    public $default = [
        'transport' => 'Aws.AmazonSesApi',
        'from' => 'example@example.com',
        'charset' => 'utf-8',
        'headerCharset' => 'utf-8',
    ];
}
```

### S3 StreamWrapper

```php
$client = new Aws\S3\S3Client(Configure::read('Aws'));
$client->registerStreamWrapperV2();
```

or

```php
CakePlugin::load('Aws', [
    'bootstrap' => true,
]);
```
