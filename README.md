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
creates AWS service clients, an S3 stream wrapper, a DynamoDB session handler and an Amazon
SES email transport.

## Requirements

- PHP 8.1+
- pieceofcake2/cakephp 2.12+

## Installation

```bash
composer require pieceofcake2/aws
```

Then load the plugin with `CakePlugin::load('Aws')`.

## Documentation

Documentation: https://pieceofcake2.github.io/aws/

## Contributing

See the [contribution guidelines](https://github.com/pieceofcake2/.github/blob/master/CONTRIBUTING.md).

## License

Licensed under the MIT License. See [LICENSE](LICENSE).
