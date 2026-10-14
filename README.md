# CakePHP2 AWS Plugin

[![GitHub License](https://img.shields.io/github/license/pieceofcake2/aws?label=License)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/pieceofcake2/aws?label=Packagist)](https://packagist.org/packages/pieceofcake2/aws)
![PHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/aws/php?logo=php&logoColor=%23FFFFFF&label=PHP&labelColor=%23777BB4&color=%23FFFFFF)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-brightgreen?label=PHPStan)](https://github.com/pieceofcake2/aws/actions/workflows/CI.yml)
![CakePHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/aws/pieceofcake2/cakephp?logo=cakephp&logoColor=%23FFFFFF&label=CakePHP&labelColor=%23D33C43&color=%23FFFFFF)
[![CI](https://img.shields.io/github/actions/workflow/status/pieceofcake2/aws/CI.yml?label=CI)](https://github.com/pieceofcake2/aws/actions/workflows/CI.yml)
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
        'cookie' => 'MY_APP',
        'handler' => [
            'engine' => 'Aws.DynamoDbSession',
            'table_name' => 'DYNAMODB_TABLE_NAME',
            // Optional: other options of Aws\DynamoDb\SessionHandler (default: 'locking' => true)
            // 'locking' => true,
        ],
        'timeout' => 1440,
        'ini' => [
            'session.cookie_lifetime' => 0,
            // Written to the "expires" attribute of each item
            'session.gc_maxlifetime' => 259200,
        ],
    ]);
```

### Removing expired sessions

`Aws\DynamoDb\SessionHandler::gc()` does nothing, so PHP's garbage collection never removes expired sessions.
`DynamoDbSession` turns it off (`session.gc_probability = 0`).
Enable [Time to Live](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/TTL.html) on the table with the `expires` attribute, and DynamoDB removes expired sessions:

```
aws dynamodb update-time-to-live --table-name DYNAMODB_TABLE_NAME \
    --time-to-live-specification "Enabled=true,AttributeName=expires"
```

The table needs a string partition key named `id`.

If several applications share a domain and a table, give each application its own session cookie name (`Session.cookie`) so that they do not read each other's sessions.

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
