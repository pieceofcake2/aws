<?php

use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\Middleware;

App::uses('AwsComponent', 'Aws.Controller/Component');
App::uses('ComponentCollection', 'Controller');
App::uses('DynamoDbSession', 'Aws.Model/Datasource/Session');
App::uses('SessionHandlerAdapter', 'Model/Datasource/Session');

/**
 * Runs the DynamoDB session handler against a real DynamoDB endpoint, e.g. DynamoDB Local:
 *
 * ```
 * docker run --rm -d -p 8000:8000 amazon/dynamodb-local
 * DYNAMODB_ENDPOINT=http://localhost:8000 vendor/bin/phpunit tests/TestCase/Model/Datasource/Session
 * ```
 *
 * The AWS SDK's session handler sends `AttributeUpdates` without `Action`, relying on the `PUT` default of
 * DynamoDB, which DynamoDB Local does not apply. The handler under test gets a client that adds it.
 */
class DynamoDbSessionIntegrationTest extends CakeTestCase
{
    private array $sessionConfig;

    private string $table;

    private ?DynamoDbClient $client = null;

    public function setUp(): void
    {
        parent::setUp();
        $endpoint = getenv('DYNAMODB_ENDPOINT');
        if (!$endpoint) {
            $this->markTestSkipped('Set DYNAMODB_ENDPOINT to run the DynamoDB session integration tests.');
        }

        Configure::write('Aws', [
            'region' => 'us-east-1',
            'version' => 'latest',
            'endpoint' => $endpoint,
            'credentials' => ['key' => 'local', 'secret' => 'local'],
        ]);
        $this->sessionConfig = (array)Configure::read('Session');
        $this->table = 'sessions_' . bin2hex(random_bytes(4));
        Configure::write('Session.timeout', 60);
        Configure::write('Session.handler', [
            'engine' => 'Aws.DynamoDbSession',
            'table_name' => $this->table,
        ]);

        $this->client = (new AwsComponent(new ComponentCollection()))->createDynamoDb();
        $this->client->createTable([
            'TableName' => $this->table,
            'BillingMode' => 'PAY_PER_REQUEST',
            'AttributeDefinitions' => [['AttributeName' => 'id', 'AttributeType' => 'S']],
            'KeySchema' => [['AttributeName' => 'id', 'KeyType' => 'HASH']],
        ]);
        $this->client->waitUntil('TableExists', ['TableName' => $this->table]);
    }

    private function sessionClient(): DynamoDbClient
    {
        $client = (new AwsComponent(new ComponentCollection()))->createDynamoDb();
        $client->getHandlerList()->appendInit(Middleware::mapCommand(function (CommandInterface $command) {
            if (isset($command['AttributeUpdates'])) {
                $updates = $command['AttributeUpdates'];
                foreach ($updates as $name => $update) {
                    $updates[$name] += ['Action' => 'PUT'];
                }
                $command['AttributeUpdates'] = $updates;
            }

            return $command;
        }), 'dynamodb_local_default_action');

        return $client;
    }

    public function tearDown(): void
    {
        if ($this->client !== null) {
            $this->client->deleteTable(['TableName' => $this->table]);
            Configure::write('Session', $this->sessionConfig);
        }
        Configure::delete('Aws');
        parent::tearDown();
    }

    private function openSession(array $options = []): DynamoDbSession
    {
        if ($options) {
            Configure::write('Session.handler', $options + (array)Configure::read('Session.handler'));
        }
        $session = new DynamoDbSession($this->sessionClient());
        $this->assertTrue($session->open());

        return $session;
    }

    private function getItem(string $id): array
    {
        $result = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => ['id' => ['S' => session_name() . '_' . $id]],
            'ConsistentRead' => true,
        ]);

        return $result['Item'] ?? [];
    }

    private function putItem(string $id, string $data, int $expires): void
    {
        $this->client->putItem([
            'TableName' => $this->table,
            'Item' => [
                'id' => ['S' => session_name() . '_' . $id],
                'data' => ['S' => $data],
                'expires' => ['N' => (string)$expires],
            ],
        ]);
    }

    public function testWriteStoresExpiresFromSessionTimeout(): void
    {
        $session = $this->openSession();
        $this->assertSame('', $session->read('abc'));
        $this->assertTrue($session->write('abc', 'Auth|a:1:{s:2:"id";i:1;}'));

        $item = $this->getItem('abc');
        $this->assertSame('Auth|a:1:{s:2:"id";i:1;}', $item['data']['S']);
        $this->assertArrayNotHasKey('lock', $item);
        $this->assertEqualsWithDelta(time() + 3600, (int)$item['expires']['N'], 5);

        $this->assertSame('Auth|a:1:{s:2:"id";i:1;}', $this->openSession(['locking' => false])->read('abc'));
    }

    public function testExpiredSessionIsGoneAfterRead(): void
    {
        $this->putItem('old', 'stale', time() - 10);

        $session = $this->openSession();
        $this->assertSame('', $session->read('old'));
        $this->assertSame([], $this->getItem('old'));

        // Closing the empty session does not bring the old data back.
        $this->assertTrue($session->write('old', ''));
        $this->assertArrayNotHasKey('data', $this->getItem('old'));
        $this->assertSame('', $this->openSession()->read('old'));
    }

    public function testExpiredSessionIsGoneAfterReadWithoutLocking(): void
    {
        $this->putItem('old', 'stale', time() - 10);

        $this->assertSame('', $this->openSession(['locking' => false])->read('old'));
        $this->assertSame([], $this->getItem('old'));
    }

    public function testValidSessionWrittenByAnotherHandlerIsRead(): void
    {
        // The item format of the AWS SDK's handler and of pieceofcake2/async-aws.
        $this->putItem('abc', 'shared', time() + 60);

        $this->assertSame('shared', $this->openSession()->read('abc'));
        $this->assertSame('1', $this->getItem('abc')['lock']['N']);
    }

    public function testGcDoesNotRemoveAnything(): void
    {
        $this->putItem('old', 'stale', time() - 10);

        $this->assertTrue($this->openSession()->gc(0));
        $this->assertSame('stale', $this->getItem('old')['data']['S']);
    }

    public function testSessionRegenerateIdKeepsData(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('A session is already active.');
        }
        $ini = [
            'session.use_cookies' => '0',
            'session.use_only_cookies' => '0',
            'session.use_strict_mode' => '0',
            'session.cache_limiter' => '',
        ];
        $before = [];
        foreach ($ini as $key => $value) {
            $before[$key] = (string)ini_get($key);
            ini_set($key, $value);
        }
        $name = session_name();

        try {
            session_set_save_handler(new SessionHandlerAdapter(new DynamoDbSession($this->sessionClient())), false);
            session_name('DYNAMODB_TEST');
            session_id('regenerate0test0session0id00001');
            $this->assertTrue(session_start());
            $_SESSION['Auth'] = ['id' => 1];
            session_write_close();

            $this->assertTrue(session_start());
            $this->assertSame(['id' => 1], $_SESSION['Auth']);
            $this->assertTrue(session_regenerate_id(true));
            $newId = session_id();
            session_write_close();

            $this->assertNotSame('regenerate0test0session0id00001', $newId);
            $this->assertSame([], $this->client->getItem([
                'TableName' => $this->table,
                'Key' => ['id' => ['S' => 'DYNAMODB_TEST_regenerate0test0session0id00001']],
                'ConsistentRead' => true,
            ])['Item'] ?? []);

            $_SESSION = [];
            session_id($newId);
            $this->assertTrue(session_start());
            $this->assertSame(['id' => 1], $_SESSION['Auth']);
            session_write_close();
        } finally {
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_abort();
            }
            $_SESSION = [];
            session_name($name);
            foreach ($before as $key => $value) {
                ini_set($key, $value);
            }
        }
    }
}
