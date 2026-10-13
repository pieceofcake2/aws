<?php

use Aws\DynamoDb\DynamoDbClient;
use Aws\S3\S3Client;

App::uses('AwsComponent', 'Aws.Controller/Component');
App::uses('ComponentCollection', 'Controller');

class AwsComponentTest extends CakeTestCase
{
    public function setUp(): void
    {
        parent::setUp();
        Configure::write('Aws', [
            'region' => 'ap-northeast-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
        ]);
    }

    public function tearDown(): void
    {
        Configure::delete('Aws');
        parent::tearDown();
    }

    private function component(): AwsComponent
    {
        return new AwsComponent(new ComponentCollection());
    }

    public function testGetCreatesClientWithConfiguredArgs(): void
    {
        $client = $this->component()->S3;

        $this->assertInstanceOf(S3Client::class, $client);
        $this->assertSame('ap-northeast-1', $client->getRegion());
    }

    public function testCreateMethodOverridesConfiguredArgs(): void
    {
        $client = $this->component()->createDynamoDb(['region' => 'us-west-2']);

        $this->assertInstanceOf(DynamoDbClient::class, $client);
        $this->assertSame('us-west-2', $client->getRegion());
    }

    public function testCallIsProxiedToSdk(): void
    {
        $client = $this->component()->createClient('s3');

        $this->assertInstanceOf(S3Client::class, $client);
    }

    public function testLoadFromComponentCollection(): void
    {
        $Aws = (new ComponentCollection())->load('Aws.Aws');

        $this->assertInstanceOf(AwsComponent::class, $Aws);
    }
}
