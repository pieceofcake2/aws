# Amazon SES メールトランスポート

`Aws.AmazonSesApi` は、Amazon SES API v2 の `SendEmail` オペレーションでメールを送信します。
`config/email.php` (レガシーレイアウトでは `app/Config/email.php`) で、メールプロファイルの
トランスポートとして設定します。

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

SES クライアントは `Aws` の設定で作成されます。メールプロファイルの次のキーは、その設定を上書きします。

| キー | 説明 |
| --- | --- |
| `region` | SES の AWS リージョン。 |
| `version` | API バージョン。 |
| `key`, `secret` | アクセスキー ID とシークレットアクセスキー。設定しない場合は、`Aws` の設定の認証情報か、デフォルトの認証情報プロバイダーチェーンが使われます。 |

トランスポートは、テキストと HTML のパート、`To`、`Cc`、`Bcc`、`Reply-To` のアドレス、
そして添付ファイルを送信します。`CakeEmail::send()` は SES のメッセージ ID を `id` として返します。

```php
App::uses('CakeEmail', 'Network/Email');

$email = new CakeEmail('default');
$result = $email->to('user@example.com')
    ->subject('Welcome')
    ->send('Hello!');

$messageId = $result['id'];
```

送信元アドレス (`from`) は SES で検証済みでなければなりません。
