<?php

use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\SessionHandler;
use Aws\DynamoDb\StandardSessionConnection;
use Aws\MockHandler;
use Aws\Result;
use Psr\Http\Message\RequestInterface;

App::uses('CakeLog', 'Log');
App::uses('CakeLogInterface', 'Log');
App::uses('DynamoDbSession', 'Aws.Model/Datasource/Session');
App::uses('DynamoDbSessionConnection', 'Aws.Model/Datasource/Session');

/**
 * Collects the log messages written during a test.
 */
class DynamoDbSessionTestLog implements CakeLogInterface
{
    public static array $messages = [];

    public function __construct($config = [])
    {
    }

    public function write($type, $message): void
    {
        static::$messages[] = $type . ': ' . $message;
    }
}

class DynamoDbSessionTest extends CakeTestCase
{
    private MockHandler $mock;

    private array $sessionConfig;

    private array $disabledLogs = [];

    /**
     * @var array<\Aws\CommandInterface>
     */
    private array $commands = [];

    public function setUp(): void
    {
        parent::setUp();
        $this->sessionConfig = (array)Configure::read('Session');
        $this->mock = new MockHandler();
        $this->commands = [];
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
        Configure::write('Session.handler.table_name', 'sessions');

        foreach (CakeLog::configured() as $stream) {
            if (CakeLog::enabled($stream)) {
                CakeLog::disable($stream);
                $this->disabledLogs[] = $stream;
            }
        }
        DynamoDbSessionTestLog::$messages = [];
        CakeLog::config('dynamodb_session_test', ['engine' => 'DynamoDbSessionTestLog', 'scopes' => ['session']]);
    }

    public function tearDown(): void
    {
        CakeLog::drop('dynamodb_session_test');
        foreach ($this->disabledLogs as $stream) {
            CakeLog::enable($stream);
        }
        Configure::delete('Aws');
        Configure::write('Session', $this->sessionConfig);
        parent::tearDown();
    }

    private function error(string $code): callable
    {
        return function (CommandInterface $command) use ($code) {
            return new DynamoDbException($code, $command, ['code' => $code, 'message' => $code . ' message']);
        };
    }

    /**
     * @return array<string>
     */
    private function commandNames(): array
    {
        return array_map(fn(CommandInterface $command) => $command->getName(), $this->commands);
    }

    private function scanPage(array $ids, ?string $lastKey = null): Result
    {
        $page = ['Items' => array_map(fn(string $id) => ['id' => ['S' => $id]], $ids)];
        if ($lastKey !== null) {
            $page['LastEvaluatedKey'] = ['id' => ['S' => $lastKey]];
        }

        return new Result($page);
    }

    private function deletedIds(CommandInterface $command): array
    {
        return array_map(fn(array $request) => $request['DeleteRequest']['Key']['id']['S'], $command['RequestItems']['sessions']);
    }

    private function item(string $data, int $expires): Result
    {
        return new Result([
            'Attributes' => [
                'id' => ['S' => session_name() . '_abc'],
                'data' => ['S' => $data],
                'expires' => ['N' => (string)$expires],
            ],
        ]);
    }

    private function openSession(): DynamoDbSession
    {
        $session = new DynamoDbSession();
        $this->assertTrue($session->open());

        return $session;
    }

    private function lastCommand(): CommandInterface
    {
        return $this->mock->getLastCommand();
    }

    public function testConstructWithoutTableName(): void
    {
        Configure::delete('Session.handler.table_name');

        $this->expectException(CakeException::class);
        $this->expectExceptionMessage('DynamoDB session handler "table_name" is not configured.');
        new DynamoDbSession();
    }

    public function testConstructDoesNotReplaceSaveHandler(): void
    {
        $before = ini_get('session.save_handler');
        new DynamoDbSession();

        $this->assertSame($before, ini_get('session.save_handler'));
    }

    public function testConstructTurnsOffPhpGarbageCollection(): void
    {
        $before = ini_get('session.gc_probability');
        ini_set('session.gc_probability', '1');
        try {
            new DynamoDbSession();

            $this->assertSame('0', ini_get('session.gc_probability'));
        } finally {
            ini_set('session.gc_probability', (string)$before);
        }
    }

    public function testConstructKeepsConfiguredGcProbability(): void
    {
        Configure::write('Session.ini', ['session.gc_probability' => 1]);
        $before = ini_get('session.gc_probability');
        ini_set('session.gc_probability', '1');
        try {
            new DynamoDbSession();

            $this->assertSame('1', ini_get('session.gc_probability'));
        } finally {
            ini_set('session.gc_probability', (string)$before);
        }
    }

    public function testUpdateItemWithoutActionGetsPut(): void
    {
        // The SDK's handler relies on the default action, which DynamoDB Local does not apply.
        $this->mock->append(new Result([]), new Result([]));

        $session = $this->openSession();
        $session->read('abc');

        $this->assertSame(['Value' => ['N' => '1'], 'Action' => 'PUT'], $this->lastCommand()['AttributeUpdates']['lock']);
    }

    public function testInjectedClient(): void
    {
        $mock = new MockHandler([new Result([])]);
        $client = new DynamoDbClient([
            'region' => 'us-east-1',
            'version' => 'latest',
            'credentials' => ['key' => 'other-key', 'secret' => 'other-secret'],
            'handler' => $mock,
        ]);
        $session = new DynamoDbSession($client);
        $session->open();

        $this->assertTrue($session->write('abc', 'serialized'));
        $this->assertSame('UpdateItem', $mock->getLastCommand()->getName());
        $this->assertCount(0, $this->mock);
    }

    public function testReadUsesLockingByDefault(): void
    {
        $this->mock->append(new Result(['Attributes' => []]));

        $this->openSession()->read('abc');

        $this->assertSame('UpdateItem', $this->lastCommand()->getName());
    }

    public function testHandlerOptionsArePassedToSdk(): void
    {
        Configure::write('Session.handler', [
            'engine' => 'Aws.DynamoDbSession',
            'table_name' => 'sessions',
            'locking' => false,
            'session_lifetime' => 259200,
        ]);
        Configure::write('Session.timeout', 30);
        $this->mock->append(new Result([]));

        $session = $this->openSession();
        $session->write('abc', 'serialized');

        $command = $this->lastCommand();
        $this->assertSame('UpdateItem', $command->getName());
        $expires = (int)$command['AttributeUpdates']['expires']['Value']['N'];
        $this->assertEqualsWithDelta(time() + 259200, $expires, 5);
    }

    public function testRead(): void
    {
        $this->mock->append(new Result([
            'Attributes' => [
                'id' => ['S' => session_name() . '_abc'],
                'data' => ['S' => 'serialized'],
                'expires' => ['N' => (string)(time() + 60)],
            ],
        ]));

        $this->assertSame('serialized', $this->openSession()->read('abc'));

        $command = $this->lastCommand();
        $this->assertSame('UpdateItem', $command->getName());
        $this->assertSame('sessions', $command['TableName']);
        $this->assertSame(['id' => ['S' => session_name() . '_abc']], $command['Key']);
        $this->assertSame(['lock' => ['Exists' => false]], $command['Expected']);
    }

    public function testReadExpired(): void
    {
        $this->mock->append($this->item('serialized', time() - 60));

        $this->assertSame('', $this->openSession()->read('abc'));

        // The item is left to Time to Live.
        $this->assertSame(['UpdateItem'], $this->commandNames());
        $this->assertSame([], DynamoDbSessionTestLog::$messages);
    }

    public function testReadExpiredWithoutLocking(): void
    {
        Configure::write('Session.handler.locking', false);
        $this->mock->append(new Result(['Item' => ['data' => ['S' => 'serialized'], 'expires' => ['N' => (string)(time() - 1)]]]));

        $this->assertSame('', $this->openSession()->read('abc'));
        $this->assertSame(['GetItem'], $this->commandNames());
    }

    public function testReadExpiresWhenTheExpirationTimeIsReached(): void
    {
        $this->mock->append($this->item('serialized', time()));

        $this->assertSame('', $this->openSession()->read('abc'));
        $this->assertSame(['UpdateItem'], $this->commandNames());
    }

    public function testReadSessionExpiringDuringTheReadIsNotDeleted(): void
    {
        // SessionHandler::read() checks the expiration time again after the connection; it must not delete the session.
        $this->mock->append($this->item('serialized', time() + 1));

        $session = $this->openSession();
        $this->assertSame('serialized', $session->read('abc'));
        $this->assertSame(['UpdateItem'], $this->commandNames());
    }

    public function testWriteAfterExpiredReadClearsTheData(): void
    {
        Configure::write('Session.timeout', 60);
        $this->mock->append($this->item('serialized', time() - 60), new Result([]));

        $session = $this->openSession();
        $this->assertSame('', $session->read('abc'));
        // PHP writes the empty session; the old data must not be kept with a new expiration time.
        $this->assertTrue($session->write('abc', ''));

        $command = $this->lastCommand();
        $this->assertSame('UpdateItem', $command->getName());
        $this->assertSame(['Action' => 'DELETE'], $command['AttributeUpdates']['data']);
        $this->assertSame('PUT', $command['AttributeUpdates']['expires']['Action']);
        $this->assertEqualsWithDelta(time() + 3600, (int)$command['AttributeUpdates']['expires']['Value']['N'], 5);
    }

    public function testWriteAfterExpiredReadWritesTheSameData(): void
    {
        $this->mock->append($this->item('serialized', time() - 60), new Result([]));

        $session = $this->openSession();
        $this->assertSame('', $session->read('abc'));
        $this->assertTrue($session->write('abc', 'serialized'));

        $this->assertSame(['Value' => ['S' => 'serialized'], 'Action' => 'PUT'], $this->lastCommand()['AttributeUpdates']['data']);
    }

    public function testCloseAfterExpiredReadClearsTheData(): void
    {
        $this->mock->append($this->item('serialized', time() - 60), new Result([]));

        $session = $this->openSession();
        $this->assertSame('', $session->read((string)session_id()));
        $this->assertTrue($session->close());

        $command = $this->lastCommand();
        $this->assertSame('UpdateItem', $command->getName());
        $this->assertSame(['Action' => 'DELETE'], $command['AttributeUpdates']['data']);
    }

    public function testWriteUnchangedDataOnlyUpdatesExpiration(): void
    {
        $this->mock->append($this->item('serialized', time() + 60), new Result([]));

        $session = $this->openSession();
        $this->assertSame('serialized', $session->read('abc'));
        $this->assertTrue($session->write('abc', 'serialized'));

        $command = $this->lastCommand();
        $this->assertSame('UpdateItem', $command->getName());
        $this->assertArrayNotHasKey('data', $command['AttributeUpdates']);
        $this->assertArrayHasKey('expires', $command['AttributeUpdates']);
    }

    public function testWrite(): void
    {
        $this->mock->append(new Result([]));

        $this->assertTrue($this->openSession()->write('abc', 'serialized'));

        $command = $this->lastCommand();
        $this->assertSame('UpdateItem', $command->getName());
        $this->assertSame(['id' => ['S' => session_name() . '_abc']], $command['Key']);
        $this->assertSame(['Value' => ['S' => 'serialized'], 'Action' => 'PUT'], $command['AttributeUpdates']['data']);
        $this->assertSame(['Action' => 'DELETE'], $command['AttributeUpdates']['lock']);
    }

    public function testWriteSetsExpiresFromSessionTimeout(): void
    {
        Configure::write('Session.timeout', 30);
        $this->mock->append(new Result([]));

        $this->assertTrue($this->openSession()->write('abc', 'serialized'));

        $expires = (int)$this->lastCommand()['AttributeUpdates']['expires']['Value']['N'];
        $this->assertEqualsWithDelta(time() + 1800, $expires, 5);
    }

    public function testWriteSetsExpiresFromGcMaxlifetimeWithoutSessionTimeout(): void
    {
        Configure::delete('Session.timeout');
        $before = ini_get('session.gc_maxlifetime');
        ini_set('session.gc_maxlifetime', '600');
        try {
            $this->mock->append(new Result([]));

            $this->assertTrue($this->openSession()->write('abc', 'serialized'));
        } finally {
            ini_set('session.gc_maxlifetime', (string)$before);
        }

        $expires = (int)$this->lastCommand()['AttributeUpdates']['expires']['Value']['N'];
        $this->assertEqualsWithDelta(time() + 600, $expires, 5);
    }

    public function testDestroy(): void
    {
        $this->mock->append(new Result([]));

        $this->assertTrue($this->openSession()->destroy('abc'));

        $command = $this->lastCommand();
        $this->assertSame('DeleteItem', $command->getName());
        $this->assertSame(['id' => ['S' => session_name() . '_abc']], $command['Key']);
    }

    public function testClose(): void
    {
        $this->mock->append(new Result([]));

        $this->assertTrue($this->openSession()->close());
        $this->assertSame('UpdateItem', $this->lastCommand()->getName());
    }

    public function testGcDeletesExpiredSessionsOfAllScanPages(): void
    {
        $this->mock->append(
            $this->scanPage(['s_1', 's_2'], 's_2'),
            new Result([]),
            $this->scanPage(['s_3']),
            new Result([]),
        );

        $this->assertTrue($this->openSession()->gc(1440));

        $this->assertSame(['Scan', 'BatchWriteItem', 'Scan', 'BatchWriteItem'], $this->commandNames());
        $scan = $this->commands[0];
        $this->assertSame('sessions', $scan['TableName']);
        $this->assertSame('#expires <= :now', $scan['FilterExpression']);
        $this->assertSame(['#id' => 'id', '#expires' => 'expires'], $scan['ExpressionAttributeNames']);
        $this->assertEqualsWithDelta(time(), (int)$scan['ExpressionAttributeValues'][':now']['N'], 5);
        $this->assertSame('#id', $scan['ProjectionExpression']);
        $this->assertSame(['id' => ['S' => 's_2']], $this->commands[2]['ExclusiveStartKey']);
        $this->assertSame(['s_1', 's_2'], $this->deletedIds($this->commands[1]));
        $this->assertSame(['s_3'], $this->deletedIds($this->commands[3]));
        $this->assertSame([], DynamoDbSessionTestLog::$messages);
    }

    public function testGcDeletesInBatchesOf25(): void
    {
        $ids = array_map(fn(int $i) => 's_' . $i, range(1, 30));
        $this->mock->append($this->scanPage($ids), new Result([]), new Result([]));

        $connection = $this->connection();
        $this->assertSame(30, $connection->deleteExpiredSessions());

        $this->assertSame(['Scan', 'BatchWriteItem', 'BatchWriteItem'], $this->commandNames());
        $this->assertSame(array_slice($ids, 0, 25), $this->deletedIds($this->commands[1]));
        $this->assertSame(array_slice($ids, 25), $this->deletedIds($this->commands[2]));
    }

    public function testGcRetriesUnprocessedItems(): void
    {
        $unprocessed = ['sessions' => [['DeleteRequest' => ['Key' => ['id' => ['S' => 's_2']]]]]];
        $this->mock->append(
            $this->scanPage(['s_1', 's_2']),
            new Result(['UnprocessedItems' => $unprocessed]),
            new Result(['UnprocessedItems' => []]),
        );

        $this->assertSame(2, $this->connection()->deleteExpiredSessions());

        $this->assertSame(['Scan', 'BatchWriteItem', 'BatchWriteItem'], $this->commandNames());
        $this->assertSame(['s_2'], $this->deletedIds($this->commands[2]));
        $this->assertSame([], DynamoDbSessionTestLog::$messages);
    }

    public function testGcGivesUpOnUnprocessedItems(): void
    {
        $unprocessed = ['sessions' => [['DeleteRequest' => ['Key' => ['id' => ['S' => 's_2']]]]]];
        $this->mock->append($this->scanPage(['s_1', 's_2']));
        for ($i = 0; $i <= DynamoDbSessionConnection::BATCH_RETRIES; $i++) {
            $this->mock->append(new Result(['UnprocessedItems' => $unprocessed]));
        }

        $this->assertSame(1, $this->connection()->deleteExpiredSessions());

        $this->assertCount(2 + DynamoDbSessionConnection::BATCH_RETRIES, $this->commands);
        $this->assertSame(['warning: DynamoDB session garbage collection could not delete 1 expired sessions from table "sessions".'], DynamoDbSessionTestLog::$messages);
    }

    public function testGcScanFailureIsLogged(): void
    {
        $this->mock->append($this->error('AccessDeniedException'));

        $this->assertTrue($this->openSession()->gc());

        $this->assertSame(['warning: DynamoDB session garbage collection of table "sessions" failed: AccessDeniedException message'], DynamoDbSessionTestLog::$messages);
    }

    public function testGcBatchFailureIsLogged(): void
    {
        $this->mock->append($this->scanPage(['s_1']), $this->error('AccessDeniedException'));

        $this->assertSame(0, $this->connection()->deleteExpiredSessions());

        $this->assertCount(2, DynamoDbSessionTestLog::$messages);
        $this->assertStringContainsString('AccessDeniedException message', DynamoDbSessionTestLog::$messages[0]);
    }

    public function testGcWithoutExpiredSessions(): void
    {
        $this->mock->append($this->scanPage([]));

        $this->assertTrue($this->openSession()->gc());
        $this->assertSame(['Scan'], $this->commandNames());
    }

    public function testSdkGarbageCollectUsesTheSameDeletion(): void
    {
        $this->mock->append($this->scanPage(['s_1']), new Result([]));
        $client = new DynamoDbClient((array)Configure::read('Aws'));
        $connection = new DynamoDbSessionConnection(new StandardSessionConnection($client, ['table_name' => 'sessions']), $client);

        (new SessionHandler($connection))->garbageCollect();

        $this->assertSame(['Scan', 'BatchWriteItem'], $this->commandNames());
    }

    private function connection(): DynamoDbSessionConnection
    {
        $client = new DynamoDbClient((array)Configure::read('Aws'));

        return new DynamoDbSessionConnection(new StandardSessionConnection($client, ['table_name' => 'sessions']), $client);
    }
}
