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
[Migrating from pieceofcake2/aws](https://pieceofcake2.github.io/async-aws/migrating-from-aws.html).

- [Installation](#installation)
- [AWS Configuration](#aws-configuration)
- [AWS Component](component.md)
- [S3 Stream Wrapper](s3.md)
- [DynamoDB Session Handler](session.md)
- [DynamoDB Cache](cache.md)
- [Amazon SES Email Transport](email.md)

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

To also register the [S3 stream wrapper](s3.md), load the plugin with its
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

## Origin

This package was created by [pieceofcake2](https://github.com/pieceofcake2), which also maintains
it. It is not a fork of another project and is not based on one.
