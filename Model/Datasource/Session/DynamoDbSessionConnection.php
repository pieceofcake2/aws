<?php

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\SessionConnectionInterface;
use Aws\DynamoDb\StandardSessionConnection;
use Aws\Exception\AwsException;

App::uses('CakeLog', 'Log');

/**
 * Session connection used by `DynamoDbSession`
 *
 * Wraps the SDK's `StandardSessionConnection` or `LockingSessionConnection`:
 *
 *  - `read()` returns no item for a session whose `expires` time has passed. The item is not deleted;
 *    DynamoDB's Time to Live removes it.
 *  - The next `write()` of that session always writes the data, so an expired session is never renewed
 *    with its old data.
 *  - `deleteExpiredSessions()` scans the table for expired sessions and deletes them in batches. It is called by
 *    `DynamoDbSession::gc()`, which PHP calls only when `session.gc_probability` is not 0.
 *
 * @internal
 */
class DynamoDbSessionConnection implements SessionConnectionInterface
{
    /**
     * Maximum number of delete requests in one BatchWriteItem call.
     *
     * @var int
     */
    public const BATCH_SIZE = 25;

    /**
     * Number of times unprocessed items of a BatchWriteItem call are sent again.
     *
     * @var int
     */
    public const BATCH_RETRIES = 3;

    /**
     * Session IDs (`<session name>_<session id>`) whose last read found an expired session.
     *
     * @var array<string, true>
     */
    private array $expired = [];

    /**
     * Constructor
     *
     * @param \Aws\DynamoDb\StandardSessionConnection $connection The SDK's connection.
     * @param \Aws\DynamoDb\DynamoDbClient $client The client used by the connection.
     */
    public function __construct(
        private StandardSessionConnection $connection,
        private DynamoDbClient $client,
    ) {
    }

    /**
     * Returns the data attribute name. Called by `Aws\DynamoDb\SessionHandler`.
     *
     * @return string
     */
    public function getDataAttribute()
    {
        return $this->connection->getDataAttribute();
    }

    /**
     * Returns the expiration time attribute name. Called by `Aws\DynamoDb\SessionHandler`.
     *
     * @return string
     */
    public function getSessionLifetimeAttribute()
    {
        return $this->connection->getSessionLifetimeAttribute();
    }

    /**
     * Reads (and, with locking, locks) the session item.
     *
     * @param string $id Session ID (`<session name>_<session id>`)
     * @return array<string, mixed> The item, or an empty array when it does not exist or has expired
     */
    public function read($id)
    {
        $item = $this->connection->read($id);
        unset($this->expired[$id]);

        $expires = $item[$this->getSessionLifetimeAttribute()] ?? null;
        if ($expires !== null && (int)$expires <= time()) {
            // Time to Live removes the item; until then it is an empty session.
            $this->expired[$id] = true;

            return [];
        }
        if ($expires !== null) {
            // The expiration time is checked here. SessionHandler::read() checks it again a moment later and
            // would delete the session if it expired in between, so it must not see the real value.
            $item[$this->getSessionLifetimeAttribute()] = PHP_INT_MAX;
        }

        return $item;
    }

    /**
     * Writes the session item, updates its expiration time and releases the lock.
     *
     * @param string $id Session ID (`<session name>_<session id>`)
     * @param string $data Session data
     * @param bool $isChanged Whether the data must be written
     * @return bool
     */
    public function write($id, $data, $isChanged)
    {
        if (isset($this->expired[$id])) {
            // Replace the data of the expired session, even when the new data is empty.
            $isChanged = true;
            unset($this->expired[$id]);
        }

        return $this->connection->write($id, $data, $isChanged);
    }

    /**
     * Deletes the session item.
     *
     * @param string $id Session ID (`<session name>_<session id>`)
     * @return bool
     */
    public function delete($id)
    {
        unset($this->expired[$id]);

        return $this->connection->delete($id);
    }

    /**
     * Deletes the expired sessions. Called by `Aws\DynamoDb\SessionHandler::garbageCollect()`.
     *
     * @return bool
     */
    public function deleteExpired()
    {
        $this->deleteExpiredSessions();

        return true;
    }

    /**
     * Deletes the expired sessions: scans the whole table for items whose `expires` time has passed and
     * deletes them with BatchWriteItem. Errors are logged with the `session` scope, never raised.
     *
     * @return int Number of deleted sessions
     */
    public function deleteExpiredSessions(): int
    {
        $table = $this->connection->getTableName();
        $hashKey = $this->connection->getHashKey();
        $deleted = 0;

        try {
            $pages = $this->client->getPaginator('Scan', [
                'TableName' => $table,
                'ProjectionExpression' => '#id',
                'FilterExpression' => '#expires <= :now',
                'ExpressionAttributeNames' => ['#id' => $hashKey, '#expires' => $this->getSessionLifetimeAttribute()],
                'ExpressionAttributeValues' => [':now' => ['N' => (string)time()]],
            ]);
            foreach ($pages as $page) {
                $requests = [];
                foreach ($page['Items'] ?? [] as $item) {
                    $requests[] = ['DeleteRequest' => ['Key' => [$hashKey => $item[$hashKey]]]];
                }
                foreach (array_chunk($requests, self::BATCH_SIZE) as $chunk) {
                    $deleted += $this->batchDelete($table, $chunk);
                }
            }
        } catch (AwsException $e) {
            $this->logGcError($table, $e);
        }

        return $deleted;
    }

    /**
     * Sends delete requests with BatchWriteItem, sending unprocessed items again a limited number of times.
     *
     * @param string $table Table name
     * @param array<array<string, mixed>> $requests Delete requests (at most 25)
     * @return int Number of processed requests
     */
    private function batchDelete(string $table, array $requests): int
    {
        $total = count($requests);
        for ($attempt = 0; $requests && $attempt <= self::BATCH_RETRIES; $attempt++) {
            if ($attempt > 0) {
                usleep(50000 * 2 ** ($attempt - 1));
            }
            try {
                $result = $this->client->batchWriteItem(['RequestItems' => [$table => $requests]]);
            } catch (AwsException $e) {
                $this->logGcError($table, $e);
                break;
            }
            $requests = $result['UnprocessedItems'][$table] ?? [];
        }

        if ($requests) {
            CakeLog::write(LOG_WARNING, sprintf(
                'DynamoDB session garbage collection could not delete %d expired sessions from table "%s".',
                count($requests),
                $table,
            ), ['session']);
        }

        return $total - count($requests);
    }

    /**
     * Logs an error of the garbage collection.
     *
     * @param string $table Table name
     * @param \Aws\Exception\AwsException $e Error
     * @return void
     */
    private function logGcError(string $table, AwsException $e): void
    {
        CakeLog::write(LOG_WARNING, sprintf(
            'DynamoDB session garbage collection of table "%s" failed: %s',
            $table,
            $e->getAwsErrorMessage() ?: $e->getMessage(),
        ), ['session']);
    }
}
