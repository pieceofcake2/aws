<?php

use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\Exception\DynamoDbException;
use Aws\DynamoDb\StandardSessionConnection;
use Aws\MockHandler;
use Aws\Result;

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

    public function setUp(): void
    {
        parent::setUp();
        $this->sessionConfig = (array)Configure::read('Session');
        $this->mock = new MockHandler();
        Configure::write('Aws', [
            'region' => 'ap-northeast-1',
            'version' => 'latest',
            'credentials' => ['key' => 'test-key', 'secret' => 'test-secret'],
            'handler' => $this->mock,
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
        $expires = time() - 60;
        $this->mock->append($this->item('serialized', $expires), new Result([]));

        $this->assertSame('', $this->openSession()->read('abc'));

        $command = $this->lastCommand();
        $this->assertSame('DeleteItem', $command->getName());
        $this->assertSame('sessions', $command['TableName']);
        $this->assertSame(['id' => ['S' => session_name() . '_abc']], $command['Key']);
        $this->assertSame('#expires = :expires', $command['ConditionExpression']);
        $this->assertSame(['#expires' => 'expires'], $command['ExpressionAttributeNames']);
        $this->assertSame([':expires' => ['N' => (string)$expires]], $command['ExpressionAttributeValues']);
        $this->assertCount(0, $this->mock);
        $this->assertSame([], DynamoDbSessionTestLog::$messages);
    }

    public function testReadExpiredWithoutLocking(): void
    {
        Configure::write('Session.handler.locking', false);
        $this->mock->append(
            new Result(['Item' => ['data' => ['S' => 'serialized'], 'expires' => ['N' => (string)(time() - 1)]]]),
            new Result([]),
        );

        $this->assertSame('', $this->openSession()->read('abc'));
        $this->assertSame('DeleteItem', $this->lastCommand()->getName());
    }

    public function testReadExpiresWhenTheExpirationTimeIsReached(): void
    {
        $this->mock->append($this->item('serialized', time()), new Result([]));

        $this->assertSame('', $this->openSession()->read('abc'));
        $this->assertSame('DeleteItem', $this->lastCommand()->getName());
    }

    public function testReadExpiredWithoutData(): void
    {
        $this->mock->append(new Result(['Attributes' => ['expires' => ['N' => (string)(time() - 60)]]]), new Result([]));

        $this->assertSame('', $this->openSession()->read('abc'));
        $this->assertSame('DeleteItem', $this->lastCommand()->getName());
    }

    public function testReadExpiredDeleteFailureIsLogged(): void
    {
        $this->mock->append($this->item('serialized', time() - 60), $this->error('AccessDeniedException'));

        $this->assertSame('', $this->openSession()->read('abc'));

        $this->assertCount(1, DynamoDbSessionTestLog::$messages);
        $this->assertStringContainsString('warning: DynamoDB session could not delete expired session "' . session_name() . '_abc" from table "sessions"', DynamoDbSessionTestLog::$messages[0]);
        $this->assertStringContainsString('AccessDeniedException message', DynamoDbSessionTestLog::$messages[0]);
    }

    public function testReadExpiredRenewedByAnotherRequestIsNotLogged(): void
    {
        $this->mock->append($this->item('serialized', time() - 60), $this->error('ConditionalCheckFailedException'));

        $this->assertSame('', $this->openSession()->read('abc'));
        $this->assertSame([], DynamoDbSessionTestLog::$messages);
    }

    public function testWriteAfterExpiredReadReplacesTheData(): void
    {
        $this->mock->append(
            $this->item('serialized', time() - 60),
            $this->error('AccessDeniedException'),
            new Result([]),
        );

        $session = $this->openSession();
        $this->assertSame('', $session->read('abc'));
        // PHP writes the empty session; the old data must not be kept with a new expiration time.
        $this->assertTrue($session->write('abc', ''));

        $command = $this->lastCommand();
        $this->assertSame('UpdateItem', $command->getName());
        $this->assertSame(['Action' => 'DELETE'], $command['AttributeUpdates']['data']);
        $this->assertArrayHasKey('expires', $command['AttributeUpdates']);
    }

    public function testCloseAfterExpiredReadReplacesTheData(): void
    {
        $this->mock->append($this->item('serialized', time() - 60), new Result([]), new Result([]));

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
        $this->assertSame(['Value' => ['S' => 'serialized']], $command['AttributeUpdates']['data']);
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

    public function testGcDoesNotCallDynamoDb(): void
    {
        $this->mock->append(new Result([]));

        $this->assertTrue($this->openSession()->gc(time()));
        $this->assertTrue($this->openSession()->gc());
        $this->assertCount(1, $this->mock);
    }

    public function testConnectionDeleteExpiredDoesNotScan(): void
    {
        $client = new DynamoDbClient((array)Configure::read('Aws'));
        $connection = new DynamoDbSessionConnection(new StandardSessionConnection($client, ['table_name' => 'sessions']), $client);

        $this->assertTrue($connection->deleteExpired());
        $this->assertCount(0, $this->mock);
    }
}
