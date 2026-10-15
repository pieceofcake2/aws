<?php

use Aws\DynamoDb\DynamoDbClient;

App::uses('AwsComponent', 'Aws.Controller/Component');
App::uses('ComponentCollection', 'Controller');
App::uses('DynamoDbSession', 'Aws.Model/Datasource/Session');
App::uses('SessionHandlerAdapter', 'Model/Datasource/Session');

/**
 * Counts the calls of gc().
 */
class DynamoDbSessionIntegrationTestSession extends DynamoDbSession
{
    public static int $gcCalls = 0;

    public function gc($expires = null): bool
    {
        static::$gcCalls++;

        return parent::gc($expires);
    }
}

/**
 * Runs the DynamoDB session handler against a real DynamoDB endpoint, e.g. DynamoDB Local:
 *
 * ```
 * docker run --rm -d -p 8000:8000 amazon/dynamodb-local
 * DYNAMODB_ENDPOINT=http://localhost:8000 vendor/bin/phpunit tests/TestCase/Model/Datasource/Session
 * ```
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
        $session = new DynamoDbSession();
        $this->assertTrue($session->open());

        return $session;
    }

    private function getItem(string $id, ?string $sessionName = null): array
    {
        $result = $this->client->getItem([
            'TableName' => $this->table,
            'Key' => ['id' => ['S' => ($sessionName ?? session_name()) . '_' . $id]],
            'ConsistentRead' => true,
        ]);

        return $result['Item'] ?? [];
    }

    private function putItem(string $id, string $data, int $expires, ?string $sessionName = null): void
    {
        $this->client->putItem([
            'TableName' => $this->table,
            'Item' => [
                'id' => ['S' => ($sessionName ?? session_name()) . '_' . $id],
                'data' => ['S' => $data],
                'expires' => ['N' => (string)$expires],
            ],
        ]);
    }

    /**
     * Runs `$callback` with PHP sessions that use the handler and no cookies.
     *
     * @param callable $callback Callback
     * @param array<string, string> $ini Other session settings
     * @return void
     */
    private function withPhpSession(callable $callback, array $ini = []): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->markTestSkipped('A session is already active.');
        }
        $ini += [
            'session.use_cookies' => '0',
            'session.use_only_cookies' => '0',
            'session.use_strict_mode' => '0',
            'session.cache_limiter' => '',
        ];
        $ini['session.gc_divisor'] ??= '1';
        $before = [];
        foreach (array_unique([...array_keys($ini), 'session.gc_probability']) as $key) {
            $before[$key] = (string)ini_get($key);
        }
        foreach ($ini as $key => $value) {
            ini_set($key, $value);
        }
        $name = session_name();
        DynamoDbSessionIntegrationTestSession::$gcCalls = 0;

        try {
            // Like CakeSession: the ini settings are applied first, then the handler is created.
            session_set_save_handler(new SessionHandlerAdapter(new DynamoDbSessionIntegrationTestSession()), false);
            session_name('DYNAMODB_TEST');
            $callback();
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

    public function testExpiredSessionIsEmptyAndNotDeletedOnRead(): void
    {
        $this->putItem('old', 'stale', time() - 10);

        $session = $this->openSession();
        $this->assertSame('', $session->read('old'));
        // Time to Live removes the item, not the read.
        $this->assertSame('stale', $this->getItem('old')['data']['S']);

        // Writing the empty session clears the old data.
        $this->assertTrue($session->write('old', ''));
        $item = $this->getItem('old');
        $this->assertArrayNotHasKey('data', $item);
        $this->assertArrayNotHasKey('lock', $item);
        $this->assertEqualsWithDelta(time() + 3600, (int)$item['expires']['N'], 5);
        $this->assertSame('', $this->openSession()->read('old'));
    }

    public function testExpiredSessionIsEmptyWithoutLocking(): void
    {
        $this->putItem('old', 'stale', time() - 10);

        $this->assertSame('', $this->openSession(['locking' => false])->read('old'));
        $this->assertSame('stale', $this->getItem('old')['data']['S']);
    }

    public function testValidSessionWrittenByAnotherHandlerIsRead(): void
    {
        // The item format of the AWS SDK's handler and of pieceofcake2/async-aws.
        $this->putItem('abc', 'shared', time() + 60);

        $this->assertSame('shared', $this->openSession()->read('abc'));
        $this->assertSame('1', $this->getItem('abc')['lock']['N']);
    }

    public function testGcDeletesOnlyExpiredSessions(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->putItem('old' . $i, 'stale', time() - $i);
        }
        $this->putItem('now', 'valid', time() + 60);
        $this->putItem('later', 'valid', time() + 3600);

        $this->assertTrue($this->openSession()->gc());

        $items = $this->client->scan(['TableName' => $this->table, 'ConsistentRead' => true])['Items'];
        $ids = array_map(fn(array $item) => $item['id']['S'], $items);
        sort($ids);
        $this->assertSame([session_name() . '_later', session_name() . '_now'], $ids);
    }

    public function testPhpDoesNotCallGcWithTheDocumentedConfiguration(): void
    {
        // CakeSession applies Session.ini before it creates the handler.
        Configure::write('Session.ini', ['session.gc_probability' => 0]);
        $this->putItem('old', 'stale', time() - 10, 'DYNAMODB_TEST');

        // gc_divisor is 1, so PHP would call gc() on every start if gc_probability were 1.
        $this->withPhpSession(function () {
            session_id('gc0test0session0id0000000000001');
            $this->assertTrue(session_start());
            session_write_close();
        }, ['session.gc_probability' => '0']);

        $this->assertSame(0, DynamoDbSessionIntegrationTestSession::$gcCalls);
        $this->assertSame('stale', $this->getItem('old', 'DYNAMODB_TEST')['data']['S']);
    }

    public function testPhpDoesNotCallGcWithoutConfiguration(): void
    {
        $this->putItem('old', 'stale', time() - 10, 'DYNAMODB_TEST');

        // PHP's default gc_probability is 1; the handler sets it to 0.
        $this->withPhpSession(function () {
            session_id('gc0test0session0id0000000000001');
            $this->assertTrue(session_start());
            session_write_close();
        }, ['session.gc_probability' => '1']);

        $this->assertSame(0, DynamoDbSessionIntegrationTestSession::$gcCalls);
        $this->assertSame('stale', $this->getItem('old', 'DYNAMODB_TEST')['data']['S']);
    }

    public function testPhpCallsGcWhenEnabled(): void
    {
        Configure::write('Session.ini', ['session.gc_probability' => 1, 'session.gc_divisor' => 1]);
        $this->putItem('old', 'stale', time() - 10, 'DYNAMODB_TEST');

        $this->withPhpSession(function () {
            session_id('gc0test0session0id0000000000001');
            $this->assertTrue(session_start());
            session_write_close();
        }, ['session.gc_probability' => '1']);

        $this->assertSame(1, DynamoDbSessionIntegrationTestSession::$gcCalls);
        $this->assertSame([], $this->getItem('old', 'DYNAMODB_TEST'));
        $this->assertNotSame([], $this->getItem('gc0test0session0id0000000000001', 'DYNAMODB_TEST'));
    }

    public function testSessionRegenerateIdKeepsData(): void
    {
        $this->withPhpSession(function () {
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
            $this->assertSame([], $this->getItem('regenerate0test0session0id00001', 'DYNAMODB_TEST'));

            $_SESSION = [];
            session_id($newId);
            $this->assertTrue(session_start());
            $this->assertSame(['id' => 1], $_SESSION['Auth']);
            session_write_close();
        });
    }
}
