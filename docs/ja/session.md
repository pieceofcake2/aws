# DynamoDB セッションハンドラー

`Aws.DynamoDbSession` は、`Aws\DynamoDb\SessionHandler` を使ってセッションを DynamoDB のテーブルに
保存します。`config/core.php` (レガシー構成では `app/Config/core.php`) で設定します。

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
        // Expired sessions are removed by DynamoDB's Time to Live, not by PHP's garbage collection
        'session.gc_probability' => 0,
    ],
]);
```

- `handler.table_name` は必須です。指定がない場合は `CakeException` が投げられます。
- `handler` のほかのキー (`engine` を除く) は、SDK のセッションハンドラーに渡されます。たとえば
  `locking`、`session_lifetime`、`consistent_read` です。`'locking' => false` を設定しない限り、
  セッションのロックは有効です。
- `session_lifetime` は、セッションが期限切れになるまでの秒数です。デフォルトは `Session.timeout` の分数、
  つまり `CakeSession` がチェックするタイムアウトなので、セッションのアイテムはセッションと同時に
  期限切れになります。`Session.timeout` がない場合のデフォルトは `session.gc_maxlifetime` です。
- テーブルは、`id` という名前の文字列型のパーティションキーを持つ状態で存在している必要があり、
  `expires` 属性で Time to Live が有効になっていなければなりません
  ([セッションテーブルの作成](#creating-the-session-table) をご覧ください)。
- DynamoDB クライアントは `Aws` の設定で作成されます。そのため、セッションが開始される前に
  プラグインを読み込み、`Aws` を設定しておく必要があります。
- 複数のアプリケーションでドメインとテーブルを共有する場合は、互いのセッションを読み込まないように、
  アプリケーションごとに別のセッションクッキー名 (`Session.cookie`) を付けてください。

アイテムは AWS SDK のセッションハンドラーのものと同じです。キーは `<session name>_<session id>`、
データは文字列、有効期限は `expires` に入る Unix タイムスタンプで、`lock` 属性はロックされた
セッションを示します。pieceofcake2/async-aws も同じアイテムを書き込むので、両方のプラグインで同じ
テーブルを使えます。

<a id="creating-the-session-table"></a>

## セッションテーブルの作成

AWS CLI でテーブルを作成し、`expires` 属性で Time to Live を有効にします。

```bash
aws dynamodb create-table --table-name DYNAMODB_TABLE_NAME \
    --attribute-definitions AttributeName=id,AttributeType=S \
    --key-schema AttributeName=id,KeyType=HASH \
    --billing-mode PAY_PER_REQUEST
aws dynamodb update-time-to-live --table-name DYNAMODB_TABLE_NAME \
    --time-to-live-specification "Enabled=true,AttributeName=expires"
```

<a id="how-sessions-expire"></a>

## セッションの有効期限切れ

期限切れのセッションは DynamoDB 自身が削除します。

- セッションを書き込んだり閉じたりするたびに、その有効期限 (現在時刻に `session_lifetime` を
  足したもの) が `expires` 属性に保存されます。
- DynamoDB の [Time to Live](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/TTL.html)
  が、`expires` の時刻を過ぎたアイテムを削除します。これはバックグラウンドで無料で実行されますが、
  すぐには削除されません。期限切れのアイテムは最大で約 48 時間、場合によってはそれ以上テーブルに
  残ることがあります。
- それまでの間、ハンドラーは期限切れのセッションを削除せずに空のセッションとして読み込み、次にその
  セッションを書き込むときに古いデータを置き換えます。期限切れのセッションが復活することはありません。

PHP のセッションのガベージコレクションはオフです。例では `session.gc_probability` を `0` にしています。
また、`Session.ini` に `session.gc_probability` が含まれていない場合も、`DynamoDbSession` がこれを
`0` に設定します。

<a id="garbage-collection"></a>

## ガベージコレクション

Time to Live を使わないテーブルのために、`DynamoDbSession::gc()` で期限切れのセッションを削除することも
できます。`gc()` はテーブル全体をスキャンして `expires` の時刻を過ぎたアイテムを探し、`BatchWriteItem`
で削除します (1 リクエストあたり 25 アイテムで、未処理のアイテムは数回リトライします)。エラーは
`session` スコープでログに記録され、リクエストを中断することはありません。

スキャンはテーブルのすべてのアイテムを読み込み、その分の料金がかかります。また、PHP が
ガベージコレクションに選んだリクエストの中で実行されます。そのため、Time to Live が有効な場合は
オフのままにしてください。オンにするには、`Session.ini` で確率を設定します。たとえば、セッション開始 1000 回に
1 回の場合は次のようにします。

```php
Configure::write('Session', [
    // ...
    'ini' => [
        'session.cookie_lifetime' => 0,
        'session.gc_probability' => 1,
        'session.gc_divisor' => 1000,
    ],
]);
```

SDK の `SessionHandler::garbageCollect()` も同じ方法で削除します。

<a id="dynamodb-local"></a>

## DynamoDB Local

[DynamoDB Local](https://docs.aws.amazon.com/amazondynamodb/latest/developerguide/DynamoDBLocal.html)
は Time to Live の設定を受け付けますが、期限切れのアイテムを削除するかどうかはバージョンによります
(現在の `amazon/dynamodb-local` イメージは削除します)。期限切れのセッションは常に空として読み込まれます。
テーブルに残る場合は、手動で削除するか、ローカルの開発環境では上記のようにガベージコレクションを
オンにしてください。

SDK のセッションハンドラーは `Action` を指定せずに `UpdateItem` リクエストを送信し、DynamoDB の
デフォルトである `PUT` に頼っていますが、DynamoDB Local はこのデフォルトを適用しません。
`DynamoDbSession` はこれらのリクエストに `'Action' => 'PUT'` を追加するので、DynamoDB Local にも
セッションが保存されます。
