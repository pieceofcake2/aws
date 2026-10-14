<?php

App::uses('Cache', 'Cache');
App::uses('DynamoDbEngine', 'Aws.Cache/Engine');

/**
 * Runs the DynamoDB cache engine against a real DynamoDB endpoint, e.g. DynamoDB Local:
 *
 * ```
 * docker run --rm -d -p 8000:8000 amazon/dynamodb-local
 * DYNAMODB_ENDPOINT=http://localhost:8000 vendor/bin/phpunit tests/TestCase/Lib/Cache/Engine
 * ```
 */
class DynamoDbEngineIntegrationTest extends CakeTestCase
{
    private ?DynamoDbEngine $engine = null;

    private string $table;

    public function setUp(): void
    {
        parent::setUp();
        $endpoint = getenv('DYNAMODB_ENDPOINT');
        if (!$endpoint) {
            $this->markTestSkipped('Set DYNAMODB_ENDPOINT to run the DynamoDB cache integration tests.');
        }

        Configure::write('Cache.disable', false);
        Configure::write('Aws', [
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => $endpoint,
            'credentials' => ['key' => 'local', 'secret' => 'local'],
        ]);
        $this->table = 'cake_cache_' . bin2hex(random_bytes(4));
        $this->engine = $this->config();
        $this->engine->createTable();
    }

    public function tearDown(): void
    {
        if ($this->engine !== null) {
            $this->engine->getClient()->deleteTable(['TableName' => $this->table]);
        }
        Cache::drop('dynamodb');
        Cache::drop('dynamodb_other');
        Cache::drop('dynamodb_groups');
        Configure::delete('Aws');
        parent::tearDown();
    }

    private function config(array $settings = [], string $name = 'dynamodb'): DynamoDbEngine
    {
        Cache::config($name, $settings + [
            'engine' => 'Aws.DynamoDb',
            'table' => $this->table,
            'prefix' => 'test_',
            'duration' => 3600,
            'consistentRead' => true,
        ]);
        $engine = Cache::engine($name);
        $this->assertInstanceOf(DynamoDbEngine::class, $engine);

        return $engine;
    }

    public function testTimeToLiveIsEnabled(): void
    {
        $result = $this->engine->getClient()->describeTimeToLive(['TableName' => $this->table]);

        $this->assertSame('expires', $result['TimeToLiveDescription']['AttributeName']);
    }

    public function testWriteAndRead(): void
    {
        $values = [
            'string' => 'value',
            'int' => 42,
            'negative' => -7,
            'float' => 1.5,
            'array' => ['a' => 1, 'b' => [true, null]],
            'object' => new ArrayObject([1, 2]),
            'binary' => "\x00\xff\xfe",
            'true' => true,
        ];
        foreach ($values as $key => $value) {
            $this->assertTrue(Cache::write($key, $value, 'dynamodb'));
        }
        foreach ($values as $key => $value) {
            $this->assertEquals($value, Cache::read($key, 'dynamodb'), $key);
        }
        $this->assertFalse(Cache::read('missing', 'dynamodb'));
    }

    public function testExpiredItemIsNotRead(): void
    {
        $this->assertTrue($this->engine->write('test_expired', 'value', 1));
        $this->assertSame('value', $this->engine->read('test_expired'));

        sleep(2);

        $this->assertFalse($this->engine->read('test_expired'));
        $this->assertFalse($this->engine->increment('test_expired'));
        $this->assertTrue($this->engine->add('test_expired', 'new', 60));
        $this->assertSame('new', $this->engine->read('test_expired'));
    }

    public function testAdd(): void
    {
        $this->assertTrue(Cache::add('key', 'first', 'dynamodb'));
        $this->assertFalse(Cache::add('key', 'second', 'dynamodb'));
        $this->assertSame('first', Cache::read('key', 'dynamodb'));
    }

    public function testIncrementAndDecrement(): void
    {
        $this->assertFalse(Cache::increment('count', 1, 'dynamodb'));

        $this->assertTrue(Cache::write('count', 5, 'dynamodb'));
        $this->assertSame(6, Cache::increment('count', 1, 'dynamodb'));
        $this->assertSame(9, Cache::increment('count', 3, 'dynamodb'));
        $this->assertSame(7, Cache::decrement('count', 2, 'dynamodb'));
        $this->assertSame(7, Cache::read('count', 'dynamodb'));
    }

    public function testIncrementNonNumeric(): void
    {
        $this->assertTrue(Cache::write('key', 'value', 'dynamodb'));

        $this->assertFalse($this->engine->increment('test_key'));
    }

    public function testDelete(): void
    {
        $this->assertTrue(Cache::write('key', 'value', 'dynamodb'));

        $this->assertTrue(Cache::delete('key', 'dynamodb'));
        $this->assertFalse(Cache::read('key', 'dynamodb'));
        $this->assertFalse(Cache::delete('key', 'dynamodb'));
    }

    public function testClearDeletesOwnPrefixOnly(): void
    {
        $this->config(['prefix' => 'other_'], 'dynamodb_other');
        for ($i = 0; $i < 30; $i++) {
            $this->assertTrue(Cache::write('key' . $i, $i, 'dynamodb'));
        }
        $this->assertTrue(Cache::write('key', 'other', 'dynamodb_other'));

        $this->assertTrue(Cache::clear(false, 'dynamodb'));

        $this->assertFalse(Cache::read('key0', 'dynamodb'));
        $this->assertFalse(Cache::read('key29', 'dynamodb'));
        $this->assertSame('other', Cache::read('key', 'dynamodb_other'));
        $scan = $this->engine->getClient()->scan(['TableName' => $this->table, 'ConsistentRead' => true]);
        $this->assertSame(1, $scan['Count']);
    }

    public function testClearWithCheckDeletesExpiredItemsOnly(): void
    {
        $this->assertTrue($this->engine->write('test_expired', 'value', 1));
        $this->assertTrue($this->engine->write('test_valid', 'value', 3600));
        $this->assertTrue($this->engine->write('test_forever', 'value', 0));
        sleep(2);

        $this->assertTrue(Cache::clear(true, 'dynamodb'));

        $scan = $this->engine->getClient()->scan(['TableName' => $this->table, 'ConsistentRead' => true]);
        $keys = array_map(fn($item) => $item['id']['S'], $scan['Items']);
        sort($keys);
        $this->assertSame(['test_forever', 'test_valid'], $keys);
    }

    public function testWriteTooLargeItem(): void
    {
        $this->assertFalse($this->engine->write('test_large', str_repeat('x', 400 * 1024), 60));
        $this->assertFalse($this->engine->read('test_large'));
    }

    public function testGroups(): void
    {
        $this->config(['groups' => ['posts']], 'dynamodb_groups');
        $this->config(['groups' => ['posts', 'comments']], 'dynamodb_other');

        $this->assertTrue(Cache::write('key', 'posts', 'dynamodb_groups'));
        $this->assertTrue(Cache::write('key', 'both', 'dynamodb_other'));
        $this->assertSame('posts', Cache::read('key', 'dynamodb_groups'));

        $this->assertTrue(Cache::clearGroup('posts', 'dynamodb_groups'));

        $this->assertFalse(Cache::read('key', 'dynamodb_groups'));
        $this->assertFalse(Cache::read('key', 'dynamodb_other'));

        $this->assertTrue(Cache::write('key', 'again', 'dynamodb_groups'));
        $this->assertSame('again', Cache::read('key', 'dynamodb_groups'));
    }

    public function testClearGroupOfNewGroup(): void
    {
        $this->config(['groups' => ['posts']], 'dynamodb_groups');

        $this->assertTrue(Cache::clearGroup('posts', 'dynamodb_groups'));
        $this->assertTrue(Cache::write('key', 'value', 'dynamodb_groups'));
        $this->assertSame('value', Cache::read('key', 'dynamodb_groups'));
        $this->assertSame(['posts2'], Cache::engine('dynamodb_groups')->groups());
    }
}
