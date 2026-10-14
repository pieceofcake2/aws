# AWS Documentation

The AWS plugin integrates the [AWS SDK for PHP](https://github.com/aws/aws-sdk-php) with
CakePHP 2 applications running on [pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp).
It provides an `Aws` component that creates AWS service clients, an S3 stream wrapper
(`s3://` paths), a session handler that stores sessions in Amazon DynamoDB, and an email
transport that sends email with the Amazon SES API v2.

- [Installation](#installation)
- [AWS Configuration](#aws-configuration)
- [AWS Component](#aws-component)
- [S3 Stream Wrapper](#s3-stream-wrapper)
- [DynamoDB Session Handler](#dynamodb-session-handler)
- [Amazon SES Email Transport](#amazon-ses-email-transport)

## Installation

Install the plugin with [Composer](https://getcomposer.org/):

```bash
composer require pieceofcake2/aws
```

The plugin has the `cakephp-plugin` type, so it is installed into your plugins directory
by `composer/installers`. Set `installer-paths` in your application's `composer.json` so it
is installed into the right place:

| Layout | `installer-paths` |
| --- | --- |
| Modern (`config/`, `src/`, `templates/`, `plugins/`) | `"plugins/{$name}/": ["type:cakephp-plugin"]` |
| Legacy (`app/Config/`, `app/Plugin/`) | `"app/Plugin/{$name}/": ["type:cakephp-plugin"]` |

Load the plugin in `config/bootstrap.php` (legacy layout: `app/Config/bootstrap.php`).
If you already use `CakePlugin::loadAll()`, this is not necessary:

```php
CakePlugin::load('Aws');
```

To also register the [S3 stream wrapper](#s3-stream-wrapper), load the plugin with its
bootstrap:

```php
CakePlugin::load('Aws', ['bootstrap' => true]);
```

## AWS Configuration

All clients are created from the `Aws` configuration, which is passed to `Aws\Sdk` as
the shared configuration of the SDK. Set it in `config/core.php` (legacy layout:
`app/Config/core.php`), or in `config/bootstrap.php` before the plugin is loaded:

```php
Configure::write('Aws', [
    'region' => 'ap-northeast-1',
    'version' => 'latest',
]);
```

When no `credentials` are configured, the SDK uses its default credential provider chain:
environment variables, the shared credentials file, and the IAM role of the EC2 instance,
ECS task or Lambda function. Any other client option of the SDK, such as `credentials` or
`endpoint`, can be added to this array.

## AWS Component

The `Aws.Aws` component creates AWS service clients with the `Aws` configuration:

```php
class FilesController extends AppController
{
    public $components = ['Aws.Aws'];

    public function index()
    {
        // An Aws\S3\S3Client
        $s3 = $this->Aws->S3;

        // The same, with options that override the Aws configuration
        $s3 = $this->Aws->createS3(['region' => 'us-east-1']);

        $result = $s3->listObjectsV2(['Bucket' => 'my-bucket']);
        $this->set('objects', $result['Contents'] ?? []);
    }
}
```

Reading a property (`$this->Aws->DynamoDb`) calls `createDynamoDb()` of `Aws\Sdk`, and
any method call is passed to `Aws\Sdk`, so every service of the SDK is available.

## S3 Stream Wrapper

The S3 stream wrapper lets you use `s3://bucket/key` paths with PHP's file functions
(`file_get_contents()`, `file_put_contents()`, `fopen()`, `is_file()`, ...).

Load the plugin with `'bootstrap' => true` to register it with the `Aws` configuration, or
register it yourself:

```php
use Aws\S3\S3Client;

$client = new S3Client(Configure::read('Aws'));
$client->registerStreamWrapperV2();
```

Then:

```php
file_put_contents('s3://my-bucket/reports/today.csv', $csv);
$csv = file_get_contents('s3://my-bucket/reports/today.csv');
```

## DynamoDB Session Handler

`Aws.DynamoDbSession` stores the sessions in a DynamoDB table with
`Aws\DynamoDb\SessionHandler`. Configure it in `config/core.php` (legacy layout:
`app/Config/core.php`):

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

- `handler.table_name` is required; a `CakeException` is thrown when it is missing.
- The other keys of `handler` (except `engine`) are passed to the SDK's session handler, for
  example `locking`, `session_lifetime` or `consistent_read`. Session locking is enabled
  unless you set `'locking' => false`.
- The table must exist, with a string partition key named `id`.
- The DynamoDB client is created with the `Aws` configuration, so the plugin must be loaded
  and `Aws` configured before the session starts.
- If several applications share a domain and a table, give each application its own session
  cookie name (`Session.cookie`) so that they do not read each other's sessions.

### Removing Expired Sessions

`Aws\DynamoDb\SessionHandler::gc()` does nothing, so PHP's garbage collection never removes
expired sessions, and `DynamoDbSession` turns it off (`session.gc_probability = 0`). Enable
[Time to Live](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/TTL.html) on
the table with the `expires` attribute, and DynamoDB removes the expired sessions:

```bash
aws dynamodb update-time-to-live --table-name DYNAMODB_TABLE_NAME \
    --time-to-live-specification "Enabled=true,AttributeName=expires"
```

## Amazon SES Email Transport

`Aws.AmazonSesApi` sends email with the `SendEmail` operation of the Amazon SES API v2.
Set it as the transport of an email profile in `config/email.php` (legacy layout:
`app/Config/email.php`):

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

The SES client is created with the `Aws` configuration. These keys of the email profile
override it:

| Key | Description |
| --- | --- |
| `region` | AWS region of SES. |
| `version` | API version. |
| `key`, `secret` | Access key ID and secret access key. When they are not set, the credentials of the `Aws` configuration or the default credential provider chain are used. |

The transport sends the text and HTML parts, the `To`, `Cc`, `Bcc` and `Reply-To`
addresses, and the attachments. `CakeEmail::send()` returns the SES message ID as `id`:

```php
App::uses('CakeEmail', 'Network/Email');

$email = new CakeEmail('default');
$result = $email->to('user@example.com')
    ->subject('Welcome')
    ->send('Hello!');

$messageId = $result['id'];
```

The sender address (`from`) must be verified in SES.
