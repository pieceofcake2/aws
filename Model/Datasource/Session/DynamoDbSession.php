<?php

use Aws\CommandInterface;
use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\LockingSessionConnection;
use Aws\DynamoDb\SessionHandler;
use Aws\DynamoDb\StandardSessionConnection;
use Aws\Middleware;

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
 * Each write and close stores the expiration time in the `expires` attribute: the current time plus
 * `session_lifetime`, which defaults to `Session.timeout` minutes (the timeout CakeSession checks), or
 * `session.gc_maxlifetime` when `Session.timeout` is not set.
 *
 *  - DynamoDB's Time to Live removes expired sessions; enable it on the table with the `expires` attribute.
 *  - Time to Live is lazy, so `read()` treats an expired session as empty until it is removed, and the next
 *    write replaces its data.
 *  - `gc()` scans the table and deletes the expired sessions. It is off by default: unless `Session.ini`
 *    contains `session.gc_probability`, this handler sets `session.gc_probability` to 0.
 */
class DynamoDbSession implements CakeSessionHandlerInterface
{
    private SessionHandler $handler;

    private DynamoDbSessionConnection $connection;

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
        static::addDefaultUpdateAction($dynamoDbClient);

        $options = array_diff_key((array)Configure::read('Session.handler'), array_flip(['engine', 'table_name']));

        $config = ['table_name' => $table_name] + $options + [
            'locking' => true,
            'session_lifetime' => static::defaultSessionLifetime(),
        ];
        $connection = $config['locking']
            ? new LockingSessionConnection($dynamoDbClient, $config)
            : new StandardSessionConnection($dynamoDbClient, $config);

        // CakeSession registers this class as the save handler, so the SDK's handler must not register itself.
        $this->connection = new DynamoDbSessionConnection($connection, $dynamoDbClient);
        $this->handler = new SessionHandler($this->connection);

        // Expired sessions are removed by the table's Time to Live (`expires`). gc() scans the whole table,
        // so PHP calls it only when the application configures `session.gc_probability`.
        if (!isset(((array)Configure::read('Session.ini'))['session.gc_probability']) && session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.gc_probability', '0');
        }
    }

    /**
     * Adds `'Action' => 'PUT'` to the `AttributeUpdates` that the SDK's session handler sends without an action.
     *
     * DynamoDB uses PUT when the action is missing, but DynamoDB Local does not, so sessions would not be stored there.
     *
     * @param \Aws\DynamoDb\DynamoDbClient $client DynamoDB client
     * @return void
     */
    protected static function addDefaultUpdateAction(DynamoDbClient $client): void
    {
        $client->getHandlerList()->appendInit(Middleware::mapCommand(function (CommandInterface $command) {
            if ($command->getName() === 'UpdateItem' && isset($command['AttributeUpdates'])) {
                $updates = $command['AttributeUpdates'];
                foreach ($updates as $name => $update) {
                    $updates[$name] += ['Action' => 'PUT'];
                }
                $command['AttributeUpdates'] = $updates;
            }

            return $command;
        }), 'session_default_update_action');
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
     * An expired session is read as empty. It is not deleted; the table's Time to Live removes it.
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
     * Scans the whole table and deletes the sessions whose `expires` time has passed, in batches of 25.
     * Errors are logged with the `session` scope, never raised.
     *
     * PHP calls this only when `session.gc_probability` is not 0, which this handler sets by default:
     * normally, the table's Time to Live (`expires`) removes the expired sessions.
     *
     * @param int $expires Not used; the expiration time of each session is stored in its item
     * @return bool Success
     */
    public function gc($expires = null): bool
    {
        $this->connection->deleteExpiredSessions();

        return true;
    }
}
