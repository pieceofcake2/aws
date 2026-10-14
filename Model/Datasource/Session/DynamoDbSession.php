<?php

use Aws\DynamoDb\SessionHandler;

App::uses('CakeSessionHandlerInterface', 'Model/Datasource/Session');
App::uses('ComponentCollection', 'Controller');
App::uses('AwsComponent', 'Aws.Controller/Component');
App::uses('CakeException', 'Error');

/**
 * DynamoDB session handler
 *
 * Stores sessions in DynamoDB using `Aws\DynamoDb\SessionHandler`.
 * Configure the table with `Session.handler.table_name`. Other keys of `Session.handler` (except `engine`)
 * are passed to the SDK's session handler (e.g. `locking`, `session_lifetime`, `consistent_read`).
 *
 * The SDK's `gc()` does nothing, so expired sessions are not removed by PHP's garbage collection.
 * Enable Time to Live on the table with the `expires` attribute so that DynamoDB removes them.
 * This handler turns off PHP's garbage collection (`session.gc_probability = 0`) because it would never remove anything.
 */
class DynamoDbSession implements CakeSessionHandlerInterface
{
    private SessionHandler $handler;

    /**
     * Constructor
     *
     * @throws \CakeException When `Session.handler.table_name` is not configured.
     */
    public function __construct()
    {
        $table_name = Configure::read('Session.handler.table_name');
        if (empty($table_name)) {
            throw new CakeException('DynamoDB session handler "table_name" is not configured.');
        }

        $Aws = new AwsComponent(new ComponentCollection());
        $dynamoDbClient = $Aws->createDynamoDb();

        $options = array_diff_key((array)Configure::read('Session.handler'), array_flip(['engine', 'table_name']));

        // CakeSession registers this class as the save handler, so the SDK's handler must not register itself.
        $this->handler = SessionHandler::fromClient($dynamoDbClient, ['table_name' => $table_name] + $options + ['locking' => true]);

        // The SDK's gc() does nothing; expired sessions are removed by the table's Time to Live (`expires`).
        if (session_status() !== PHP_SESSION_ACTIVE) {
            ini_set('session.gc_probability', '0');
        }
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
     * @param string $id The key of the value to read
     * @return mixed The value of the key or false if it does not exist
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
     * The SDK's handler does nothing here. Expired sessions are removed by the table's Time to Live (`expires`).
     *
     * @param int $expires Timestamp (defaults to current time)
     * @return bool Success
     */
    public function gc($expires = null): bool
    {
        return $this->handler->gc($expires);
    }
}
