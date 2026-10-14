<?php

use Aws\CommandInterface;
use Aws\MockHandler;
use Aws\Result;

App::uses('DynamoDbSession', 'Aws.Model/Datasource/Session');

class DynamoDbSessionTest extends CakeTestCase
{
    private MockHandler $mock;

    private array $sessionConfig;

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
    }

    public function tearDown(): void
    {
        Configure::delete('Aws');
        Configure::write('Session', $this->sessionConfig);
        parent::tearDown();
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
        $this->mock->append(
            new Result([
                'Attributes' => [
                    'data' => ['S' => 'serialized'],
                    'expires' => ['N' => (string)(time() - 60)],
                ],
            ]),
            new Result([]),
        );

        $this->assertSame('', $this->openSession()->read('abc'));
        $this->assertSame('DeleteItem', $this->lastCommand()->getName());
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

    public function testGc(): void
    {
        $this->assertTrue($this->openSession()->gc(time()));
    }
}
