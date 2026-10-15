# CakePHP 2 AWS Plugin

[![GitHub License](https://img.shields.io/github/license/pieceofcake2/aws?label=License)](LICENSE)
[![Packagist Version](https://img.shields.io/packagist/v/pieceofcake2/aws?label=Packagist)](https://packagist.org/packages/pieceofcake2/aws)
![PHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/aws/php?logo=php&logoColor=%23FFFFFF&label=PHP&labelColor=%23777BB4&color=%23FFFFFF)
[![PHPStan](https://img.shields.io/badge/PHPStan-level%205-brightgreen?label=PHPStan)](https://github.com/pieceofcake2/aws/actions/workflows/CI.yml)
![CakePHP](https://img.shields.io/packagist/dependency-v/pieceofcake2/aws/pieceofcake2/cakephp?logo=cakephp&logoColor=%23FFFFFF&label=CakePHP&labelColor=%23D33C43&color=%23FFFFFF)
[![CI](https://img.shields.io/github/actions/workflow/status/pieceofcake2/aws/CI.yml?label=CI)](https://github.com/pieceofcake2/aws/actions/workflows/CI.yml)
[![Codecov](https://img.shields.io/codecov/c/gh/pieceofcake2/aws?label=Coverage)](https://codecov.io/gh/pieceofcake2/aws)

The AWS plugin integrates the AWS SDK for PHP with CakePHP 2 applications running on
[pieceofcake2/cakephp](https://github.com/pieceofcake2/cakephp): an `Aws` component that
creates AWS service clients, an S3 stream wrapper, a DynamoDB session handler, a DynamoDB
cache engine (`Aws.DynamoDb`) and an Amazon SES email transport.

A lighter alternative based on [AsyncAws](https://async-aws.com):
[pieceofcake2/async-aws](https://github.com/pieceofcake2/async-aws). It provides the same
features and installs only the AWS services you use.

## Documentation

- Website: https://pieceofcake2.github.io/aws/ (日本語: https://pieceofcake2.github.io/aws/ja/)
- Markdown: [docs/README.md](docs/README.md) (日本語: [docs/ja/README.md](docs/ja/README.md)) and the pages it links (for AI tools and offline reading)

The Markdown documentation is also included in the Composer package, in the plugin's directory: `plugins/Aws/docs/README.md` (the location depends on your `installer-paths`). Install the package with `--dev` if you only need it for development.

## Requirements

- PHP 8.1+
- pieceofcake2/cakephp 2.12+

## Installation

```bash
composer require pieceofcake2/aws
```

Then load the plugin with `CakePlugin::load('Aws')`.

## Contributing

See the [contribution guidelines](https://github.com/pieceofcake2/.github/blob/master/CONTRIBUTING.md).

## Origin

This package was created by [pieceofcake2](https://github.com/pieceofcake2), which also maintains
it. It is not a fork of another project and is not based on one.

## License

Licensed under the MIT License. See [LICENSE](LICENSE).
