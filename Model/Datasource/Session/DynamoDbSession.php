<?php

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\SessionHandler;

App::uses('CakeSessionHandlerInterface', 'Model/Datasource/Session');
App::uses('ComponentCollection', 'Controller');
App::uses('CakeException', 'Error');

class DynamoDbSession implements CakeSessionHandlerInterface
{
    private SessionHandler $handler;

    public function __construct()
    {
        $table_name = Configure::read('Session.handler.table_name');
        if (empty($table_name)) {
            throw new CakeException('DynamoDB session handler "table_name" is not configured.');
        }

        /** @var AwsComponent $Aws */
        $Aws = (new ComponentCollection())
            ->load('Aws.Aws');

        /** @var DynamoDbClient $dynamoDbClient */
        $dynamoDbClient = $Aws->createDynamoDb();

        $this->handler = $dynamoDbClient->registerSessionHandler([
            'table_name' => $table_name,
            'locking' => true,
        ]);
    }

    /**
     * Method called on open of a session.
     *
     * @return bool Success
     */
    public function open(): bool
    {
        $savePath = null;
        $sessionName = session_name();

        return $this->handler->open($savePath, $sessionName);
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
     *
     * @return mixed The value of the key or false if it does not exist
     */
    public function read($id)
    {
        return $this->handler->read($id);
    }

    /**
     * Helper function called on write for sessions.
     *
     * @param int   $id   ID that uniquely identifies session in database
     * @param mixed $data the value of the data to be saved
     *
     * @return bool true for successful write, false otherwise
     */
    public function write($id, $data): bool
    {
        return $this->handler->write($id, $data);
    }

    /**
     * Method called on the destruction of a session.
     *
     * @param int $id ID that uniquely identifies session in database
     *
     * @return bool true for successful delete, false otherwise
     */
    public function destroy($id): bool
    {
        return $this->handler->destroy($id);
    }

    /**
     * Run the Garbage collection on the session storage. This method should vacuum all
     * expired or dead sessions.
     *
     * @param int $expires Timestamp (defaults to current time)
     *
     * @return bool Success
     */
    public function gc($expires = null): bool
    {
        return $this->handler->gc($expires);
    }
}
