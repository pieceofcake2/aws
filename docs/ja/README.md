# AWS ドキュメント

AWS プラグインは、[pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp) で動く CakePHP 2
アプリケーションに [AWS SDK for PHP](https://github.com/aws/aws-sdk-php) を組み込みます。
AWS のサービスクライアントを作成する `Aws` コンポーネント、S3 ストリームラッパー (`s3://` パス)、
Amazon DynamoDB にデータを保存するセッションハンドラーとキャッシュエンジン、そして Amazon SES API v2 で
メールを送信するメールトランスポートを提供します。

[AsyncAws](https://async-aws.com) をベースにした軽量な代替として
[pieceofcake2/async-aws](https://github.com/pieceofcake2/async-aws) があります。同じ機能を提供し、
使う AWS サービスだけをインストールするので、AWS Lambda などへのデプロイを小さく保てます。
[pieceofcake2/aws からの移行](https://pieceofcake2.github.io/async-aws/migrating-from-aws.html)
をご覧ください。

- [インストール](#installation)
- [AWS の設定](#aws-configuration)
- [AWS コンポーネント](component.md)
- [S3 ストリームラッパー](s3.md)
- [DynamoDB セッションハンドラー](session.md)
- [DynamoDB キャッシュ](cache.md)
- [Amazon SES メールトランスポート](email.md)

<a id="installation"></a>

## インストール

[Composer](https://getcomposer.org/) でプラグインをインストールします。

```bash
composer require pieceofcake2/aws
```

プラグインのタイプは `cakephp-plugin` なので、`composer/installers` によってプラグインディレクトリに
インストールされます。正しい場所にインストールされるように、アプリケーションの `composer.json` で
`installer-paths` を設定してください。

| 構成 | `installer-paths` |
| --- | --- |
| モダン (`config/`、`src/`、`templates/`、`plugins/`) | `"plugins/{$name}/": ["type:cakephp-plugin"]` |
| レガシー (`app/Config/`、`app/Plugin/`) | `"app/Plugin/{$name}/": ["type:cakephp-plugin"]` |

`config/bootstrap.php` (レガシー構成では `app/Config/bootstrap.php`) でプラグインを読み込みます。
すでに `CakePlugin::loadAll()` を使っている場合は不要です。

```php
CakePlugin::load('Aws');
```

[S3 ストリームラッパー](s3.md) も登録するには、プラグインをその bootstrap とともに読み込みます。

```php
CakePlugin::load('Aws', ['bootstrap' => true]);
```

<a id="aws-configuration"></a>

## AWS の設定

すべてのクライアントは `Aws` の設定から作成されます。この設定は SDK の共通設定として `Aws\Sdk` に
渡されます。`config/core.php` (レガシー構成では `app/Config/core.php`) か、プラグインを読み込む
前の `config/bootstrap.php` で設定します。

```php
Configure::write('Aws', [
    'region' => 'ap-northeast-1',
    'version' => 'latest',
]);
```

`credentials` を設定しない場合、SDK はデフォルトの認証情報プロバイダーチェーンを使います。
つまり、環境変数、共有認証情報ファイル、そして EC2 インスタンス、ECS タスク、Lambda 関数の IAM
ロールです。`credentials` や `endpoint` など、SDK のほかのクライアントオプションもこの配列に追加できます。

<a id="origin"></a>

## 由来

このパッケージは [pieceofcake2](https://github.com/pieceofcake2) が作成し、メンテナンスしています。
他のプロジェクトのフォークではなく、他のプロジェクトをもとにしたものでもありません。
