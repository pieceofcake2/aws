# AWS Documentation

The AWS plugin integrates the [AWS SDK for PHP](https://github.com/aws/aws-sdk-php) with
CakePHP 2 applications running on [pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp).
It provides an `Aws` component that creates AWS service clients, an S3 stream wrapper
(`s3://` paths), a session handler and a cache engine that store data in Amazon DynamoDB,
and an email transport that sends email with the Amazon SES API v2.

A lighter alternative based on [AsyncAws](https://async-aws.com) is
[pieceofcake2/async-aws](https://github.com/pieceofcake2/async-aws). It provides the same
features and installs only the AWS services you use, which keeps deployments small, for
example on AWS Lambda. See
[Migrating from pieceofcake2/aws](https://pieceofcake2.github.io/async-aws/#migrating-from-pieceofcake2aws).

- [Installation](#installation)
- [AWS Configuration](#aws-configuration)
- [AWS Component](#aws-component)
- [S3 Stream Wrapper](#s3-stream-wrapper)
- [DynamoDB Session Handler](#dynamodb-session-handler)
- [DynamoDB Cache](#dynamodb-cache)
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
    // Minutes; also the lifetime written to the "expires" attribute of each item
    'timeout' => 1440,
    'ini' => [
        'session.cookie_lifetime' => 0,
        // Expired sessions are removed by Time to Live, not by PHP's garbage collection
        'session.gc_probability' => 0,
    ],
]);
```

- `handler.table_name` is required; a `CakeException` is thrown when it is missing.
- The other keys of `handler` (except `engine`) are passed to the SDK's session handler, for
  example `locking`, `session_lifetime` or `consistent_read`. Session locking is enabled
  unless you set `'locking' => false`.
- `session_lifetime` is the number of seconds until a session expires. It defaults to
  `Session.timeout` minutes, the timeout that `CakeSession` checks, so a session item expires
  together with the session. Without `Session.timeout`, it defaults to
  `session.gc_maxlifetime`.
- The table must exist, with a string partition key named `id`, and Time to Live should be
  enabled on the `expires` attribute (see [Creating the Session Table](#creating-the-session-table)).
- The DynamoDB client is created with the `Aws` configuration, so the plugin must be loaded
  and `Aws` configured before the session starts.
- If several applications share a domain and a table, give each application its own session
  cookie name (`Session.cookie`) so that they do not read each other's sessions.

The items are those of the AWS SDK's session handler: the key is
`<session name>_<session id>`, the data is a string, the expiration time is a Unix timestamp in
`expires`, and a `lock` attribute marks a locked session. pieceofcake2/async-aws writes the same
items, so both plugins can use the same table.

### Creating the Session Table

Create the table and enable Time to Live with the AWS CLI:

```bash
aws dynamodb create-table --table-name DYNAMODB_TABLE_NAME \
    --attribute-definitions AttributeName=id,AttributeType=S \
    --key-schema AttributeName=id,KeyType=HASH \
    --billing-mode PAY_PER_REQUEST
aws dynamodb update-time-to-live --table-name DYNAMODB_TABLE_NAME \
    --time-to-live-specification "Enabled=true,AttributeName=expires"
```

### Removing Expired Sessions

PHP's garbage collection is not used. Expired sessions are removed in two ways instead:

- Each write and close of a session stores its expiration time (the current time plus
  `session_lifetime`) in the `expires` attribute, and DynamoDB's
  [Time to Live](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/TTL.html)
  deletes the item after that time. Time to Live runs in the background and usually deletes
  expired items within a few days.
- Until then, reading a session whose `expires` time has passed returns an empty session and
  deletes the item at once, so an expired session is never used again. This delete is best
  effort: a failure is logged with the `session` scope and does not interrupt the request, and
  the data of the expired session is removed when the empty session is written.

`gc()` does nothing, so it never scans the table. Set `session.gc_probability` to `0` as in the
example above; `DynamoDbSession` also sets it when it is created, because a call to `gc()` would
not remove anything. Do not call the SDK's `SessionHandler::garbageCollect()` either: it scans
the whole table, which is slow and expensive on a large table, and Time to Live removes the same
items without any cost.

## DynamoDB Cache

`Aws.DynamoDb` is a cache engine that stores cache entries in a DynamoDB table. Configure it
with `Cache::config()` in `config/bootstrap.php` (legacy layout: `app/Config/bootstrap.php`),
after the plugin is loaded and `Aws` is configured:

```php
Cache::config('default', [
    'engine' => 'Aws.DynamoDb',
    'table' => 'DYNAMODB_TABLE_NAME',
    'prefix' => 'my_app_',
    'duration' => '+1 hours',
]);
```

| Key | Default | Description |
| --- | --- | --- |
| `table` | (required) | Name of the DynamoDB table. Without it, `Cache::config()` throws a `CacheException`. |
| `prefix` | `<APP_DIR>_` | Prefix of the cache keys. `clear()` deletes the items with this prefix only. |
| `duration` | `3600` | Seconds, or a `strtotime()` string, until the entries expire. `0` means they never expire. |
| `groups` | `[]` | Cache groups, cleared with `Cache::clearGroup()`. |
| `consistentRead` | `false` | Use strongly consistent reads (twice the read cost). |
| `keyAttribute` | `id` | Name of the string partition key. |
| `valueAttribute` | `value` | Name of the attribute that stores the value. |
| `expiresAttribute` | `expires` | Name of the attribute that stores the expiration time (Unix epoch seconds). |
| `clientConfig` | `[]` | Client options that override the `Aws` configuration for this cache, e.g. `region` or `endpoint`. |
| `client` | `null` | An `Aws\DynamoDb\DynamoDbClient` to use instead of creating one. |
| `probability` | `0` | Not used; `gc()` does nothing because DynamoDB removes expired items. |

Integers are stored as numbers, so `Cache::increment()` and `Cache::decrement()` update them
atomically. Other values are serialized with `serialize()` and stored as binary.

### Creating the Cache Table

The table needs a string partition key (`id` by default) and Time to Live on the expiration
attribute (`expires` by default). Create it with the AWS CLI:

```bash
aws dynamodb create-table --table-name DYNAMODB_TABLE_NAME \
    --attribute-definitions AttributeName=id,AttributeType=S \
    --key-schema AttributeName=id,KeyType=HASH \
    --billing-mode PAY_PER_REQUEST
aws dynamodb update-time-to-live --table-name DYNAMODB_TABLE_NAME \
    --time-to-live-specification "Enabled=true,AttributeName=expires"
```

Or let the engine do it with its settings, for example from a shell or a setup script.
`createTable()` creates an on-demand table, waits until it exists and enables Time to Live.
Its argument is merged into the `CreateTable` request:

```php
Cache::engine('default')->createTable();

// With provisioned capacity
Cache::engine('default')->createTable([
    'BillingMode' => 'PROVISIONED',
    'ProvisionedThroughput' => ['ReadCapacityUnits' => 5, 'WriteCapacityUnits' => 5],
]);
```

Use a table of its own for the cache. Do not share it with the session handler, because
`Cache::clear()` scans the whole table.

### Expiration and Time to Live

Each item has its expiration time in the `expires` attribute. DynamoDB removes expired items
with Time to Live, but it does so in the background, typically within a few days, so the
engine also checks the expiration time: expired items are never returned by `read()`, and
`add()` overwrites them. `increment()` and `decrement()` return `false` for missing and
expired keys, like `MemcachedEngine`.

### Caveats

- Reads are eventually consistent unless `consistentRead` is `true`: a read right after a
  write, a delete or `Cache::clearGroup()` may return the previous value. With `groups`,
  every operation also reads the group versions.
- `Cache::clear()` scans the whole table and deletes the items with the configuration's
  prefix (with an empty prefix, every item). It reads, and is charged for, every item of the
  table, so it is slow and expensive on a large table. `Cache::clear(true)` deletes only the
  expired items, with the same scan.
- A DynamoDB item is limited to 400 KB, including the attribute names. A larger value is not
  written: `Cache::write()` returns `false`, triggers a warning and the error is logged with
  the `cache` scope. Errors from DynamoDB are logged in the same way.
- `Cache::clearGroup()` increments the group version, so the old entries stay in the table
  until they expire.

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
