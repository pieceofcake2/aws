<?php

use Aws\DynamoDb\DynamoDbClient;
use Aws\DynamoDb\SessionConnectionInterface;
use Aws\DynamoDb\StandardSessionConnection;
use Aws\Exception\AwsException;

App::uses('CakeLog', 'Log');

/**
 * Session connection used by `DynamoDbSession`
 *
 * Wraps the SDK's `StandardSessionConnection` or `LockingSessionConnection` and handles expired sessions:
 *
 *  - `read()` returns no item for a session whose `expires` time has passed, and deletes it at once.
 *    The delete is best effort: a failure is logged with the `session` scope and never raised,
 *    and the delete is skipped when another request has renewed the session in the meantime.
 *  - The next `write()` of that session always writes the data, so an expired session that could not
 *    be deleted is never renewed with its old data.
 *  - `deleteExpired()` does nothing. It scans the whole table; DynamoDB's Time to Live removes
 *    the expired sessions instead.
 *
 * @internal
 */
class DynamoDbSessionConnection implements SessionConnectionInterface
{
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
        if ($expires === null || (int)$expires > time()) {
            return $item;
        }

        $this->expired[$id] = true;
        $this->deleteExpiredItem($id, (int)$expires);

        return [];
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
            // Replace the data of an expired session that could not be deleted, even when the new data is empty.
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
     * Does nothing. Expired sessions are removed by the table's Time to Live and when they are read.
     *
     * @return bool
     */
    public function deleteExpired()
    {
        return true;
    }

    /**
     * Deletes an expired item unless another request renewed it. Failures are logged, not raised.
     *
     * @param string $id Session ID (`<session name>_<session id>`)
     * @param int $expires Expiration time read from the item
     * @return void
     */
    private function deleteExpiredItem(string $id, int $expires): void
    {
        try {
            $this->client->deleteItem([
                'TableName' => $this->connection->getTableName(),
                'Key' => [$this->connection->getHashKey() => ['S' => $id]],
                'ConditionExpression' => '#expires = :expires',
                'ExpressionAttributeNames' => ['#expires' => $this->getSessionLifetimeAttribute()],
                'ExpressionAttributeValues' => [':expires' => ['N' => (string)$expires]],
            ]);
        } catch (AwsException $e) {
            if ($e->getAwsErrorCode() !== 'ConditionalCheckFailedException') {
                CakeLog::write(LOG_WARNING, sprintf(
                    'DynamoDB session could not delete expired session "%s" from table "%s": %s',
                    $id,
                    $this->connection->getTableName(),
                    $e->getAwsErrorMessage() ?: $e->getMessage(),
                ), ['session']);
            }
        }
    }
}
