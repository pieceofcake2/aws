<?php

$client = new Aws\S3\S3Client((array) Configure::read('Aws'));
$client->registerStreamWrapperV2();
