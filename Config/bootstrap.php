<?php

use Aws\S3\S3Client;

$client = new S3Client((array)Configure::read('Aws'));
$client->registerStreamWrapperV2();
