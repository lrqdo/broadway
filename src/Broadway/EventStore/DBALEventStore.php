<?php

/*
 * This file is part of the broadway/broadway package.
 *
 * (c) Qandidate.com <opensource@qandidate.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Broadway\EventStore;

use Broadway\Domain\DateTime;
use Broadway\Domain\DomainEventStream;
use Broadway\Domain\DomainEventStreamInterface;
use Broadway\Domain\DomainMessage;
use Broadway\EventStore\Exception\InvalidIdentifierException;
use Broadway\EventStore\Management\Criteria;
use Broadway\EventStore\Management\CriteriaNotSupportedException;
use Broadway\EventStore\Management\EventStoreManagementInterface;
use Broadway\Serializer\SerializerInterface;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DBALException;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Statement;
use Doctrine\DBAL\Version;
use Rhumsaa\Uuid\Uuid;

/**
 * Event store using a relational database as storage.
 *
 * The implementation uses doctrine DBAL for the communication with the
 * underlying data store.
 */
class DBALEventStore implements EventStoreInterface, EventStoreManagementInterface
{
    private $connection;

    private $payloadSerializer;

    private $metadataSerializer;

    private $loadStatement = null;

    private $loadLastStatement = null;

    private $tableName;

    private $useBinary;

    /**
     * @param string $tableName
     */
    public function __construct(
        Connection $connection,
        SerializerInterface $payloadSerializer,
        SerializerInterface $metadataSerializer,
        $tableName,
        $useBinary = false
    ) {
        $this->connection         = $connection;
        $this->payloadSerializer  = $payloadSerializer;
        $this->metadataSerializer = $metadataSerializer;
        $this->tableName          = $tableName;
        $this->useBinary          = (bool) $useBinary;

        if ($this->useBinary && Version::compare('2.5.0') >= 0) {
            throw new \InvalidArgumentException(
                'The Binary storage is only available with Doctrine DBAL >= 2.5.0'
            );
        }
    }

    /**
     * {@inheritDoc}
     */
    public function load($id, $playhead = 0)
    {
        $statement = $this->prepareLoadStatement();
        $statement->bindValue('uuid', $this->convertIdentifierToStorageValue($id));
        $statement->bindValue('playhead', $playhead);
        $statement->execute();

        $events = $this->executeStatement($statement);

        if (empty($events) && $playhead === 0) {
            throw new EventStreamNotFoundException(sprintf('EventStream not found for aggregate with id %s', $id));
        }

        return new DomainEventStream($events);
    }

    /**
     * {@inheritDoc}
     */
    public function loadLast($id)
    {
        $statement = $this->prepareLoadLastStatement();
        $statement->bindValue('uuid', $this->convertIdentifierToStorageValue($id));

        $events = $this->executeStatement($statement);

        if (empty($events)) {
            return null;
        }

        return $events[0];
    }

    private function executeStatement(Statement $statement)
    {
        $statement->execute();

        $events = array();
        while ($row = $statement->fetch()) {
            $events[] = $this->deserializeEvent($row);
        }

        return $events;
    }

    /**
     * {@inheritDoc}
     */
    public function append($id, DomainEventStreamInterface $eventStream)
    {
        // noop to ensure that an error will be thrown early if the ID
        // is not something that can be converted to a string. If we
        // let this move on without doing this DBAL will eventually
        // give us a hard time but the true reason for the problem
        // will be obfuscated.
        $id = (string) $id;

        $this->connection->beginTransaction();

        try {
            foreach ($eventStream as $domainMessage) {
                $this->insertMessage($this->connection, $domainMessage);
            }

            $this->connection->commit();
        } catch (DBALException $exception) {
            $this->connection->rollback();

            throw DBALEventStoreException::create($exception);
        }
    }

    private function insertMessage(Connection $connection, DomainMessage $domainMessage)
    {
        $data = array(
            'uuid'        => $this->convertIdentifierToStorageValue((string) $domainMessage->getId()),
            'playhead'    => $domainMessage->getPlayhead(),
            'metadata'    => json_encode($this->metadataSerializer->serialize($domainMessage->getMetadata())),
            'payload'     => json_encode($this->payloadSerializer->serialize($domainMessage->getPayload())),
            'recorded_on' => $domainMessage->getRecordedOn()->toString(),
            'type'        => $domainMessage->getType(),
        );

        $connection->insert($this->tableName, $data);
    }

    /**
     * @return \Doctrine\DBAL\Schema\Table|null
     */
    public function configureSchema(Schema $schema)
    {
        if ($schema->hasTable($this->tableName)) {
            return null;
        }

        return $this->configureTable();
    }

    public function configureTable()
    {
        $schema = new Schema();

        $uuidColumnDefinition = array(
            'type'   => 'guid',
            'params' => array(
                'length' => 36,
            ),
        );

        if ($this->useBinary) {
            $uuidColumnDefinition['type']   = 'binary';
            $uuidColumnDefinition['params'] = array(
                'length' => 16,
                'fixed'  => true,
            );
        }

        $table = $schema->createTable($this->tableName);

        $table->addColumn('id', 'integer', array('autoincrement' => true));
        $table->addColumn('uuid', $uuidColumnDefinition['type'], $uuidColumnDefinition['params']);
        $table->addColumn('playhead', 'integer', array('unsigned' => true));
        $table->addColumn('payload', 'text');
        $table->addColumn('metadata', 'text');
        $table->addColumn('recorded_on', 'string', array('length' => 32));
        $table->addColumn('type', 'text');

        $table->setPrimaryKey(array('id'));
        $table->addUniqueIndex(array('uuid', 'playhead'));

        return $table;
    }

    private function prepareLoadStatement()
    {
        if (null === $this->loadStatement) {
            $query = 'SELECT uuid, playhead, metadata, payload, recorded_on
                FROM ' . $this->tableName . '
                WHERE uuid = :uuid
                AND playhead >= :playhead
                ORDER BY playhead ASC';
            $this->loadStatement = $this->connection->prepare($query);
        }

        return $this->loadStatement;
    }

    private function prepareLoadLastStatement()
    {
        if (null === $this->loadLastStatement) {
            $query = 'SELECT uuid, playhead, metadata, payload, recorded_on
                FROM ' . $this->tableName . '
                WHERE uuid = :uuid
                ORDER BY playhead DESC
                LIMIT 1';
            $this->loadLastStatement = $this->connection->prepare($query);
        }

        return $this->loadLastStatement;
    }

    private function deserializeEvent($row)
    {
        return new DomainMessage(
            $this->convertStorageValueToIdentifier($row['uuid']),
            $row['playhead'],
            $this->metadataSerializer->deserialize(json_decode($row['metadata'], true)),
            $this->payloadSerializer->deserialize(json_decode($row['payload'], true)),
            DateTime::fromString($row['recorded_on'])
        );
    }

    private function convertIdentifierToStorageValue($id)
    {
        if ($this->useBinary) {
            try {
                return Uuid::fromString($id)->getBytes();
            } catch (\Exception $e) {
                throw new InvalidIdentifierException(
                    'Only valid UUIDs are allowed to by used with the binary storage mode.'
                );
            }
        }

        return $id;
    }

    private function convertStorageValueToIdentifier($id)
    {
        if ($this->useBinary) {
            try {
                return Uuid::fromBytes($id)->toString();
            } catch (\Exception $e) {
                throw new InvalidIdentifierException(
                    'Could not convert binary storage value to UUID.'
                );
            }
        }

        return $id;
    }

    public function visitEvents(Criteria $criteria, EventVisitorInterface $eventVisitor)
    {
        $statement = $this->prepareVisitEventsStatement($criteria);
        $statement->execute();

        while ($row = $statement->fetch()) {
            $domainMessage = $this->deserializeEvent($row);

            $eventVisitor->doWithEvent($domainMessage);
        }
    }

    private function prepareVisitEventsStatement(Criteria $criteria)
    {
        list ($where, $bindValues, $bindValueTypes) = $this->prepareVisitEventsStatementWhereAndBindValues($criteria);
        $query = 'SELECT uuid, playhead, metadata, payload, recorded_on
            FROM ' . $this->tableName . '
            ' . $where . '
            ORDER BY id ASC';

        $statement = $this->connection->executeQuery($query, $bindValues, $bindValueTypes);

        return $statement;
    }

    private function prepareVisitEventsStatementWhereAndBindValues(Criteria $criteria)
    {
        if ($criteria->getAggregateRootTypes()) {
            throw new CriteriaNotSupportedException(
                'DBAL implementation cannot support criteria based on aggregate root types.'
            );
        }

        $bindValues = array();
        $bindValueTypes = array();

        $criteriaTypes = array();

        if ($criteria->getAggregateRootIds()) {
            $criteriaTypes[] = 'uuid IN (:uuids)';

            if ($this->useBinary) {
                $bindValues['uuids'] = array();
                foreach ($criteria->getAggregateRootIds() as $id) {
                    $bindValues['uuids'][] = $this->convertIdentifierToStorageValue($id);
                }
                $bindValueTypes['uuids'] = Connection::PARAM_STR_ARRAY;
            } else {
                $bindValues['uuids'] = $criteria->getAggregateRootIds();
                $bindValueTypes['uuids'] = Connection::PARAM_STR_ARRAY;
            }
        }

        if ($criteria->getEventTypes()) {
            $criteriaTypes[] = 'type IN (:types)';
            $bindValues['types'] = $criteria->getEventTypes();
            $bindValueTypes['types'] = Connection::PARAM_STR_ARRAY;
        }

        if (! $criteriaTypes) {
            return array('', array(), array());
        }

        $where = 'WHERE '.join(' AND ', $criteriaTypes);

        return array($where, $bindValues, $bindValueTypes);
    }
}
