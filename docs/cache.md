# DynamoDB Cache

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

## Creating the Cache Table

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

## Expiration and Time to Live

Each item has its expiration time in the `expires` attribute. DynamoDB removes expired items
with Time to Live, but it does so in the background, typically within a few days, so the
engine also checks the expiration time: expired items are never returned by `read()`, and
`add()` overwrites them. `increment()` and `decrement()` return `false` for missing and
expired keys, like `MemcachedEngine`.

## Caveats

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
