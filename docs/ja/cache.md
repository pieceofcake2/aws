# DynamoDB キャッシュ

`Aws.DynamoDb` は、キャッシュのエントリーを DynamoDB のテーブルに保存するキャッシュエンジンです。
プラグインを読み込み、`Aws` を設定した後に、`config/bootstrap.php` (レガシーレイアウトでは
`app/Config/bootstrap.php`) で `Cache::config()` を使って設定します。

```php
Cache::config('default', [
    'engine' => 'Aws.DynamoDb',
    'table' => 'DYNAMODB_TABLE_NAME',
    'prefix' => 'my_app_',
    'duration' => '+1 hours',
]);
```

| キー | デフォルト | 説明 |
| --- | --- | --- |
| `table` | (必須) | DynamoDB のテーブル名。指定しないと `Cache::config()` が `CacheException` をスローします。 |
| `prefix` | `<APP_DIR>_` | キャッシュキーのプレフィックス。`clear()` はこのプレフィックスを持つアイテムだけを削除します。 |
| `duration` | `3600` | エントリーが期限切れになるまでの秒数、または `strtotime()` 形式の文字列。`0` は期限切れにならないことを意味します。 |
| `groups` | `[]` | キャッシュグループ。`Cache::clearGroup()` でクリアします。 |
| `consistentRead` | `false` | 強い整合性のある読み込みを使います (読み込みのコストは 2 倍になります)。 |
| `keyAttribute` | `id` | 文字列型のパーティションキーの名前。 |
| `valueAttribute` | `value` | 値を保存する属性の名前。 |
| `expiresAttribute` | `expires` | 有効期限 (Unix エポック秒) を保存する属性の名前。 |
| `clientConfig` | `[]` | このキャッシュについて `Aws` の設定を上書きするクライアントオプション。たとえば `region` や `endpoint`。 |
| `client` | `null` | 新しく作成する代わりに使う `Aws\DynamoDb\DynamoDbClient`。 |
| `probability` | `0` | 使われません。DynamoDB が期限切れのアイテムを削除するので、`gc()` は何もしません。 |

整数は数値として保存されるので、`Cache::increment()` と `Cache::decrement()` はアトミックに値を
更新します。それ以外の値は `serialize()` でシリアライズされ、バイナリとして保存されます。

<a id="creating-the-cache-table"></a>

## キャッシュテーブルの作成

テーブルには、文字列型のパーティションキー (デフォルトは `id`) と、有効期限の属性 (デフォルトは
`expires`) での Time to Live が必要です。AWS CLI で作成します。

```bash
aws dynamodb create-table --table-name DYNAMODB_TABLE_NAME \
    --attribute-definitions AttributeName=id,AttributeType=S \
    --key-schema AttributeName=id,KeyType=HASH \
    --billing-mode PAY_PER_REQUEST
aws dynamodb update-time-to-live --table-name DYNAMODB_TABLE_NAME \
    --time-to-live-specification "Enabled=true,AttributeName=expires"
```

または、シェルやセットアップスクリプトなどから、エンジンにその設定でテーブルを作成させることも
できます。`createTable()` はオンデマンドのテーブルを作成し、テーブルが存在するまで待ってから
Time to Live を有効にします。引数は `CreateTable` リクエストにマージされます。

```php
Cache::engine('default')->createTable();

// With provisioned capacity
Cache::engine('default')->createTable([
    'BillingMode' => 'PROVISIONED',
    'ProvisionedThroughput' => ['ReadCapacityUnits' => 5, 'WriteCapacityUnits' => 5],
]);
```

キャッシュには専用のテーブルを使ってください。`Cache::clear()` はテーブル全体をスキャンするので、
セッションハンドラーとテーブルを共有しないでください。

<a id="expiration-and-time-to-live"></a>

## 有効期限と Time to Live

各アイテムは `expires` 属性に有効期限を持ちます。DynamoDB は Time to Live で期限切れのアイテムを
削除しますが、これはバックグラウンドで行われ、通常は数日以内です。そのため、エンジンも有効期限を
チェックします。期限切れのアイテムが `read()` で返されることはなく、`add()` はそれを上書きします。
`increment()` と `decrement()` は、`MemcachedEngine` と同様に、存在しないキーや期限切れのキーに対して
`false` を返します。

<a id="caveats"></a>

## 注意事項

- `consistentRead` が `true` でない限り、読み込みは結果整合性です。書き込み、削除、
  `Cache::clearGroup()` の直後の読み込みでは、以前の値が返ることがあります。`groups` を使う場合は、
  すべての操作でグループのバージョンも読み込みます。
- `Cache::clear()` はテーブル全体をスキャンし、その設定のプレフィックスを持つアイテムを削除します
  (プレフィックスが空の場合はすべてのアイテム)。テーブルのすべてのアイテムを読み込み、その分の料金が
  かかるので、大きなテーブルでは遅く、コストも高くなります。`Cache::clear(true)` は、同じスキャンで
  期限切れのアイテムだけを削除します。
- DynamoDB のアイテムは、属性名を含めて 400 KB までに制限されています。それより大きな値は書き込まれず、
  `Cache::write()` は `false` を返して警告を発生させ、エラーは `cache` スコープでログに記録されます。
  DynamoDB からのエラーも同じようにログに記録されます。
- `Cache::clearGroup()` はグループのバージョンをインクリメントするので、古いエントリーは期限切れに
  なるまでテーブルに残ります。
