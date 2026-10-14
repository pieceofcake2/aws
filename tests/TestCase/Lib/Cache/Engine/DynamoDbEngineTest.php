<?php

use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\MockHandler;
use Aws\Result;
use Psr\Http\Message\RequestInterface;

App::uses('Cache', 'Cache');
App::uses('CakeLog', 'Log');
App::uses('CakeLogInterface', 'Log');
App::uses('DynamoDbEngine', 'Aws.Cache/Engine');

/**
 * Collects the log messages written during a test.
 */
class DynamoDbEngineTestLog implements CakeLogInterface
{
    public static array $messages = [];

    public function __construct($config = [])
    {
    }

    public function write($type, $message): void
    {
        static::$messages[] = $message;
    }
}

class DynamoDbEngineTest extends CakeTestCase
{
    private MockHandler $mock;

    /**
     * @var array<\Aws\CommandInterface>
     */
    private array $commands = [];

    private array $disabledLogs = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->mock = new MockHandler();
        $mock = $this->mock;
        Configure::write('Aws', [
            'region' => 'ap-northeast-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => function (CommandInterface $command, RequestInterface $request) use ($mock) {
                $this->commands[] = $command;

                return $mock($command, $request);
            },
        ]);
        Configure::write('Cache.disable', false);

        foreach (CakeLog::configured() as $stream) {
            if (CakeLog::enabled($stream)) {
                CakeLog::disable($stream);
                $this->disabledLogs[] = $stream;
            }
        }
        DynamoDbEngineTestLog::$messages = [];
        CakeLog::config('dynamodb_engine_test', ['engine' => 'DynamoDbEngineTestLog']);
    }

    public function tearDown(): void
    {
        Cache::drop('dynamodb');
        CakeLog::drop('dynamodb_engine_test');
        foreach ($this->disabledLogs as $stream) {
            CakeLog::enable($stream);
        }
        Configure::delete('Aws');
        parent::tearDown();
    }

    private function config(array $settings = []): DynamoDbEngine
    {
        Cache::config('dynamodb', $settings + [
            'engine' => 'Aws.DynamoDb',
            'table' => 'cache',
            'prefix' => 'test_',
            'duration' => 3600,
        ]);
        $engine = Cache::engine('dynamodb');
        $this->assertInstanceOf(DynamoDbEngine::class, $engine);

        return $engine;
    }

    private function engine(array $settings = []): DynamoDbEngine
    {
        $engine = new DynamoDbEngine();
        $this->assertTrue($engine->init($settings + ['table' => 'cache', 'prefix' => 'test_']));

        return $engine;
    }

    private function lastCommand(): CommandInterface
    {
        return $this->mock->getLastCommand();
    }

    private function error(string $code): callable
    {
        return function (CommandInterface $command) use ($code) {
            return new DynamoDbException($code, $command, ['code' => $code, 'message' => $code . ' message']);
        };
    }

    private function item(string $key, array $value, ?int $expires = null): array
    {
        $item = ['id' => ['S' => $key], 'value' => $value];
        if ($expires !== null) {
            $item['expires'] = ['N' => (string)$expires];
        }

        return ['Item' => $item];
    }

    public function testInitWithoutTable(): void
    {
        $engine = new DynamoDbEngine();

        $this->assertFalse($engine->init([]));
    }

    public function testConfigWithoutTableThrows(): void
    {
        $this->expectException(CacheException::class);
        Cache::config('dynamodb', ['engine' => 'Aws.DynamoDb']);
    }

    public function testDefaultSettings(): void
    {
        $settings = $this->engine()->settings();

        $this->assertSame('Aws.DynamoDb', $settings['engine']);
        $this->assertSame('id', $settings['keyAttribute']);
        $this->assertSame('value', $settings['valueAttribute']);
        $this->assertSame('expires', $settings['expiresAttribute']);
        $this->assertFalse($settings['consistentRead']);
        $this->assertSame(0, $settings['probability']);
    }

    public function testClientUsesAwsConfiguration(): void
    {
        $client = $this->engine()->getClient();

        $this->assertInstanceOf(DynamoDbClient::class, $client);
        $this->assertSame('ap-northeast-1', $client->getRegion());
    }

    public function testClientConfigOverridesAwsConfiguration(): void
    {
        $client = $this->engine([
            'clientConfig' => ['region' => 'us-west-2', 'endpoint' => 'http://localhost:8000'],
        ])->getClient();

        $this->assertSame('us-west-2', $client->getRegion());
        $this->assertSame('http://localhost:8000', (string)$client->getEndpoint());
    }

    public function testInjectedClient(): void
    {
        $mock = new MockHandler([new Result([])]);
        $client = new DynamoDbClient([
            'region' => 'eu-west-1',
            'version' => 'latest',
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);
        $engine = $this->engine(['client' => $client]);

        $this->assertSame($client, $engine->getClient());
        $this->assertFalse($engine->read('key'));
        $this->assertSame('GetItem', $mock->getLastCommand()->getName());
        $this->assertSame(0, $this->mock->count());
    }

    public function testWriteString(): void
    {
        $this->config();
        $this->mock->append(new Result([]));

        $this->assertTrue(Cache::write('Some.Key', 'value', 'dynamodb'));

        $command = $this->lastCommand();
        $this->assertSame('PutItem', $command->getName());
        $this->assertSame('cache', $command['TableName']);
        $this->assertSame(['S' => 'test_some_key'], $command['Item']['id']);
        $this->assertSame(['B' => serialize('value')], $command['Item']['value']);
        $this->assertEqualsWithDelta(time() + 3600, (int)$command['Item']['expires']['N'], 5);
        $this->assertArrayNotHasKey('ConditionExpression', $command->toArray());
    }

    public function testWriteInteger(): void
    {
        $this->mock->append(new Result([]));

        $this->assertTrue($this->engine()->write('test_count', 5, 60));

        $this->assertSame(['N' => '5'], $this->lastCommand()['Item']['value']);
    }

    public function testWriteWithoutExpiration(): void
    {
        $this->mock->append(new Result([]));

        $this->assertTrue($this->engine()->write('test_key', 'value', 0));

        $this->assertArrayNotHasKey('expires', $this->lastCommand()['Item']);
    }

    public function testWriteCustomAttributeNames(): void
    {
        $this->mock->append(new Result([]));
        $engine = $this->engine(['keyAttribute' => 'pk', 'valueAttribute' => 'data', 'expiresAttribute' => 'ttl']);

        $this->assertTrue($engine->write('test_key', ['a' => 1], 60));

        $item = $this->lastCommand()['Item'];
        $this->assertSame(['pk', 'data', 'ttl'], array_keys($item));
        $this->assertSame(serialize(['a' => 1]), $item['data']['B']);
    }

    public function testWriteTooLargeItem(): void
    {
        $engine = $this->engine();

        $this->assertFalse($engine->write('test_large', str_repeat('x', DynamoDbEngine::MAX_ITEM_SIZE), 60));
        $this->assertSame(0, $this->mock->count());
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
        $this->assertStringContainsString('larger than the DynamoDB item size limit', DynamoDbEngineTestLog::$messages[0]);
    }

    public function testWriteFailure(): void
    {
        $this->mock->append($this->error('ValidationException'));

        $this->assertFalse($this->engine()->write('test_key', 'value', 60));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
        $this->assertStringContainsString('DynamoDB cache write of "test_key" in table "cache" failed: ValidationException', DynamoDbEngineTestLog::$messages[0]);
    }

    public function testRead(): void
    {
        $this->config(['consistentRead' => true]);
        $this->mock->append(new Result($this->item('test_key', ['B' => serialize(['a' => 1])], time() + 60)));

        $this->assertSame(['a' => 1], Cache::read('key', 'dynamodb'));

        $command = $this->lastCommand();
        $this->assertSame('GetItem', $command->getName());
        $this->assertSame(['id' => ['S' => 'test_key']], $command['Key']);
        $this->assertTrue($command['ConsistentRead']);
    }

    public function testReadInteger(): void
    {
        $this->mock->append(new Result($this->item('test_count', ['N' => '42'])));

        $this->assertSame(42, $this->engine()->read('test_count'));
    }

    public function testReadMissing(): void
    {
        $this->mock->append(new Result([]));

        $this->assertFalse($this->engine()->read('test_key'));
        $this->assertFalse($this->lastCommand()['ConsistentRead']);
    }

    public function testReadExpired(): void
    {
        $this->mock->append(new Result($this->item('test_key', ['B' => serialize('value')], time() - 1)));

        $this->assertFalse($this->engine()->read('test_key'));
    }

    public function testReadFailure(): void
    {
        $this->mock->append($this->error('ResourceNotFoundException'));

        $this->assertFalse($this->engine()->read('test_key'));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
    }

    public function testAdd(): void
    {
        $this->config();
        $this->mock->append(new Result([]));

        $this->assertTrue(Cache::add('key', 'value', 'dynamodb'));

        $command = $this->lastCommand();
        $this->assertSame('PutItem', $command->getName());
        $this->assertSame('attribute_not_exists(#k) OR #e <= :now', $command['ConditionExpression']);
        $this->assertSame(['#k' => 'id', '#e' => 'expires'], $command['ExpressionAttributeNames']);
    }

    public function testAddExisting(): void
    {
        $this->mock->append($this->error('ConditionalCheckFailedException'));

        $this->assertFalse($this->engine()->add('test_key', 'value', 60));
        $this->assertSame([], DynamoDbEngineTestLog::$messages);
    }

    public function testAddFailure(): void
    {
        $this->mock->append($this->error('ValidationException'));

        $this->assertFalse($this->engine()->add('test_key', 'value', 60));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
    }

    public function testIncrement(): void
    {
        $this->config();
        $this->mock->append(new Result(['Attributes' => ['value' => ['N' => '3']]]));

        $this->assertSame(3, Cache::increment('count', 2, 'dynamodb'));

        $command = $this->lastCommand();
        $this->assertSame('UpdateItem', $command->getName());
        $this->assertSame('ADD #v :offset', $command['UpdateExpression']);
        $this->assertSame('attribute_exists(#k) AND (attribute_not_exists(#e) OR #e > :now)', $command['ConditionExpression']);
        $this->assertSame(['N' => '2'], $command['ExpressionAttributeValues'][':offset']);
        $this->assertSame('UPDATED_NEW', $command['ReturnValues']);
    }

    public function testDecrement(): void
    {
        $this->mock->append(new Result(['Attributes' => ['value' => ['N' => '-1']]]));

        $this->assertSame(-1, $this->engine()->decrement('test_count', 3));
        $this->assertSame(['N' => '-3'], $this->lastCommand()['ExpressionAttributeValues'][':offset']);
    }

    public function testIncrementMissing(): void
    {
        $this->mock->append($this->error('ConditionalCheckFailedException'));

        $this->assertFalse($this->engine()->increment('test_count'));
        $this->assertSame([], DynamoDbEngineTestLog::$messages);
    }

    public function testIncrementNonNumeric(): void
    {
        $this->mock->append($this->error('ValidationException'));

        $this->assertFalse($this->engine()->increment('test_key'));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
    }

    public function testDelete(): void
    {
        $this->config();
        $this->mock->append(new Result(['Attributes' => ['id' => ['S' => 'test_key']]]));

        $this->assertTrue(Cache::delete('key', 'dynamodb'));

        $command = $this->lastCommand();
        $this->assertSame('DeleteItem', $command->getName());
        $this->assertSame(['id' => ['S' => 'test_key']], $command['Key']);
        $this->assertSame('ALL_OLD', $command['ReturnValues']);
    }

    public function testDeleteMissing(): void
    {
        $this->mock->append(new Result([]));

        $this->assertFalse($this->engine()->delete('test_key'));
    }

    public function testDeleteFailure(): void
    {
        $this->mock->append($this->error('ResourceNotFoundException'));

        $this->assertFalse($this->engine()->delete('test_key'));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
    }

    public function testClear(): void
    {
        $this->config();
        $keys = [];
        for ($i = 0; $i < 30; $i++) {
            $keys[] = ['id' => ['S' => 'test_' . $i]];
        }
        $this->mock->append(
            new Result(['Items' => array_slice($keys, 0, 20), 'LastEvaluatedKey' => ['id' => ['S' => 'test_19']]]),
            new Result(['Items' => array_slice($keys, 20)]),
            new Result([]),
            new Result([]),
        );
        $this->commands = [];

        $this->assertTrue(Cache::clear(false, 'dynamodb'));

        $commands = $this->commands;
        $this->assertSame(['Scan', 'Scan', 'BatchWriteItem', 'BatchWriteItem'], array_map(fn($c) => $c->getName(), $commands));
        $this->assertSame('begins_with(#k, :prefix)', $commands[0]['FilterExpression']);
        $this->assertSame([':prefix' => ['S' => 'test_']], $commands[0]['ExpressionAttributeValues']);
        $this->assertSame('#k', $commands[0]['ProjectionExpression']);
        $this->assertSame(['id' => ['S' => 'test_19']], $commands[1]['ExclusiveStartKey']);
        $this->assertCount(25, $commands[2]['RequestItems']['cache']);
        $this->assertSame(['DeleteRequest' => ['Key' => ['id' => ['S' => 'test_0']]]], $commands[2]['RequestItems']['cache'][0]);
        $this->assertCount(5, $commands[3]['RequestItems']['cache']);
    }

    public function testClearWithCheckDeletesExpiredItemsOnly(): void
    {
        $this->mock->append(new Result(['Items' => []]));

        $this->assertTrue($this->engine()->clear(true));

        $command = $this->lastCommand();
        $this->assertSame('Scan', $command->getName());
        $this->assertSame('begins_with(#k, :prefix) AND #e <= :now', $command['FilterExpression']);
        $this->assertSame(['#k' => 'id', '#e' => 'expires'], $command['ExpressionAttributeNames']);
        $this->assertEqualsWithDelta(time(), (int)$command['ExpressionAttributeValues'][':now']['N'], 5);
    }

    public function testClearWithoutPrefixScansWholeTable(): void
    {
        $this->mock->append(new Result(['Items' => []]));

        $this->assertTrue($this->engine(['prefix' => ''])->clear(false));

        $this->assertArrayNotHasKey('FilterExpression', $this->lastCommand()->toArray());
    }

    public function testClearRetriesUnprocessedItems(): void
    {
        $key = ['id' => ['S' => 'test_a']];
        $this->mock->append(
            new Result(['Items' => [$key]]),
            new Result(['UnprocessedItems' => ['cache' => [['DeleteRequest' => ['Key' => $key]]]]]),
            new Result(['UnprocessedItems' => []]),
        );

        $this->assertTrue($this->engine()->clear(false));
        $this->assertSame(0, $this->mock->count());
    }

    public function testClearFailsWhenItemsStayUnprocessed(): void
    {
        $key = ['id' => ['S' => 'test_a']];
        $unprocessed = new Result(['UnprocessedItems' => ['cache' => [['DeleteRequest' => ['Key' => $key]]]]]);
        $this->mock->append(new Result(['Items' => [$key]]), $unprocessed, $unprocessed, $unprocessed, $unprocessed, $unprocessed);

        $this->assertFalse($this->engine()->clear(false));
        $this->assertStringContainsString('could not delete 1 items', DynamoDbEngineTestLog::$messages[0]);
    }

    public function testClearFailure(): void
    {
        $this->mock->append($this->error('ResourceNotFoundException'));

        $this->assertFalse($this->engine()->clear(false));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
    }

    public function testGcDoesNothing(): void
    {
        $this->engine()->gc();

        $this->assertNull($this->mock->getLastCommand());
    }

    public function testGroups(): void
    {
        $engine = $this->engine(['groups' => ['posts', 'comments']]);
        $this->mock->append(
            new Result($this->item('test_comments', ['N' => '3'])),
            new Result([]),
            new Result(['Attributes' => ['value' => ['N' => '1']]]),
        );

        $this->assertSame(['comments3', 'posts1'], $engine->groups());

        $command = $this->lastCommand();
        $this->assertSame('UpdateItem', $command->getName());
        $this->assertSame(['id' => ['S' => 'test_posts']], $command['Key']);
        $this->assertSame('SET #v = if_not_exists(#v, :one) REMOVE #e', $command['UpdateExpression']);
    }

    public function testGroupsAreUsedInKeys(): void
    {
        $this->config(['groups' => ['posts']]);
        $this->mock->append(
            new Result($this->item('test_posts', ['N' => '2'])),
            new Result([]),
        );

        $this->assertTrue(Cache::write('key', 'value', 'dynamodb'));

        $this->assertSame(['S' => 'test_' . md5('posts2') . 'key'], $this->lastCommand()['Item']['id']);
    }

    public function testClearGroup(): void
    {
        $this->config(['groups' => ['posts']]);
        $this->mock->append(new Result([]));

        $this->assertTrue(Cache::clearGroup('posts', 'dynamodb'));

        $command = $this->lastCommand();
        $this->assertSame('UpdateItem', $command->getName());
        $this->assertSame(['id' => ['S' => 'test_posts']], $command['Key']);
        $this->assertSame('SET #v = if_not_exists(#v, :one) + :one REMOVE #e', $command['UpdateExpression']);
    }

    public function testClearGroupFailure(): void
    {
        $this->mock->append($this->error('ResourceNotFoundException'));

        $this->assertFalse($this->engine()->clearGroup('posts'));
        $this->assertCount(1, DynamoDbEngineTestLog::$messages);
    }

    public function testCreateTable(): void
    {
        $engine = $this->engine(['keyAttribute' => 'pk', 'expiresAttribute' => 'ttl']);
        $this->mock->append(
            new Result([]),
            new Result(['Table' => ['TableStatus' => 'ACTIVE']]),
            new Result([]),
        );
        $this->commands = [];

        $engine->createTable(['Tags' => [['Key' => 'app', 'Value' => 'test']]]);

        $commands = $this->commands;
        $this->assertSame(['CreateTable', 'DescribeTable', 'UpdateTimeToLive'], array_map(fn($c) => $c->getName(), $commands));
        $this->assertSame('cache', $commands[0]['TableName']);
        $this->assertSame([['AttributeName' => 'pk', 'AttributeType' => 'S']], $commands[0]['AttributeDefinitions']);
        $this->assertSame([['AttributeName' => 'pk', 'KeyType' => 'HASH']], $commands[0]['KeySchema']);
        $this->assertSame('PAY_PER_REQUEST', $commands[0]['BillingMode']);
        $this->assertSame([['Key' => 'app', 'Value' => 'test']], $commands[0]['Tags']);
        $this->assertSame(['Enabled' => true, 'AttributeName' => 'ttl'], $commands[2]['TimeToLiveSpecification']);
    }
}
