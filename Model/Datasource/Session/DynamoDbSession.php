<?php

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\LockingSessionConnection;
use Aws\DynamoDb\SessionHandler;
use Aws\DynamoDb\StandardSessionConnection;

App::uses('CakeSessionHandlerInterface', 'Model/Datasource/Session');
App::uses('ComponentCollection', 'Controller');
App::uses('AwsComponent', 'Aws.Controller/Component');
App::uses('CakeException', 'Error');
App::uses('DynamoDbSessionConnection', 'Aws.Model/Datasource/Session');

/**
 * DynamoDB session handler
 *
 * Stores sessions in DynamoDB using `Aws\DynamoDb\SessionHandler`.
 * Configure the table with `Session.handler.table_name`. Other keys of `Session.handler` (except `engine`)
 * are passed to the SDK's session handler (e.g. `locking`, `session_lifetime`, `consistent_read`).
 *
 * Each write stores the expiration time in the `expires` attribute: the current time plus `session_lifetime`,
 * which defaults to `Session.timeout` minutes (the timeout CakeSession checks), or `session.gc_maxlifetime`
 * when `Session.timeout` is not set. Expired sessions are removed without PHP's garbage collection:
 *
 *  - DynamoDB's Time to Live removes them; enable it on the table with the `expires` attribute.
 *  - Time to Live can take a few days, so `read()` treats an expired session as empty and deletes it at once
 *    (best effort: a failure is logged with the `session` scope).
 *  - `gc()` does nothing; it never scans the table. This handler turns off PHP's garbage collection
 *    (`session.gc_probability = 0`) because it would never remove anything.
 */
class DynamoDbSession implements CakeSessionHandlerInterface
{
    private SessionHandler $handler;

    /**
     * Constructor
     *
     * @param \Aws\DynamoDb\DynamoDbClient|null $dynamoDbClient DynamoDB client. Default: a client created with the `Aws` configuration.
     * @throws \CakeException When `Session.handler.table_name` is not configured.
     */
    public function __construct(?DynamoDbClient $dynamoDbClient = null)
    {
        $table_name = Configure::read('Session.handler.table_name');
        if (empty($table_name)) {
            throw new CakeException('DynamoDB session handler "table_name" is not configured.');
        }

        $dynamoDbClient ??= (new AwsComponent(new ComponentCollection()))->createDynamoDb();

        $options = array_diff_key((array)Configure::read('Session.handler'), array_flip(['engine', 'table_name']));

        $config = ['table_name' => $table_name] + $options + [
            'locking' => true,
            'session_lifetime' => static::defaultSessionLifetime(),
        ];
        $connection = $config['locking']
            ? new LockingSessionConnection($dynamoDbClient, $config)
            : new StandardSessionConnection($dynamoDbClient, $config);

        // CakeSession registers this class as the save handler, so the SDK's handler must not register itself.
        $this->handler = new SessionHandler(new DynamoDbSessionConnection($connection, $dynamoDbClient));

        // gc() does nothing; expired sessions are removed by the table's Time to Live (`expires`) and on read.
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.gc_probability', '0');
        }
    }

    /**
     * Returns the default session lifetime in seconds: `Session.timeout` minutes, the timeout that CakeSession
     * checks, or `session.gc_maxlifetime` when `Session.timeout` is not set.
     *
     * @return int
     */
    protected static function defaultSessionLifetime(): int
    {
        $timeout = Configure::read('Session.timeout');
        if (is_numeric($timeout) && $timeout > 0) {
            return (int)round($timeout * 60);
        }

        return (int)ini_get('session.gc_maxlifetime');
    }

    /**
     * Method called on open of a session.
     *
     * @return bool Success
     */
    public function open(): bool
    {
        // The SDK handler stores the save path but never uses it.
        return $this->handler->open('', session_name());
    }

    /**
     * Method called on close of a session.
     *
     * @return bool Success
     */
    public function close(): bool
    {
        return $this->handler->close();
    }

    /**
     * Method used to read from a session.
     *
     * An expired session is read as empty and deleted.
     *
     * @param string $id The key of the value to read
     * @return mixed The session data, or an empty string if it does not exist or has expired
     */
    public function read($id)
    {
        return $this->handler->read($id);
    }

    /**
     * Helper function called on write for sessions.
     *
     * @param string|int $id ID that uniquely identifies session in database
     * @param mixed $data The value of the data to be saved
     * @return bool true for successful write, false otherwise
     */
    public function write($id, $data): bool
    {
        return $this->handler->write($id, $data);
    }

    /**
     * Method called on the destruction of a session.
     *
     * @param string|int $id ID that uniquely identifies session in database
     * @return bool true for successful delete, false otherwise
     */
    public function destroy($id): bool
    {
        return $this->handler->destroy($id);
    }

    /**
     * Run the Garbage collection on the session storage.
     *
     * Does nothing and never scans the table. Expired sessions are removed by the table's Time to Live
     * (`expires`) and when they are read.
     *
     * @param int $expires Timestamp (defaults to current time)
     * @return bool Success
     */
    public function gc($expires = null): bool
    {
        return true;
    }
}
