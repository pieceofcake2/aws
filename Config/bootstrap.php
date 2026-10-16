<?php

use Aws\S3\S3Client;

App::uses('AwsSesDsn', 'Aws.Network/Email');

$client = new S3Client((array)Configure::read('Aws'));
$client->registerStreamWrapperV2();

// Symfony Mailer style DSNs of Amazon SES for the 'dsn' key of email profiles,
// e.g. 'dsn' => env('MAILER_DSN') with ses+api://ACCESS_KEY:SECRET_KEY@default?region=eu-west-1.
// Skipped on pieceofcake2/cakephp releases without CakeEmail::setDsnClassMap().
AwsSesDsn::register();
