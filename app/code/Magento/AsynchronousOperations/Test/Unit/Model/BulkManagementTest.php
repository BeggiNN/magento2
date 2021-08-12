<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */
declare(strict_types=1);

namespace Magento\AsynchronousOperations\Test\Unit\Model;

use Magento\AsynchronousOperations\Api\Data\BulkSummaryInterface;
use Magento\AsynchronousOperations\Api\Data\BulkSummaryInterfaceFactory;
use Magento\AsynchronousOperations\Api\Data\OperationInterface;
use Magento\AsynchronousOperations\Model\BulkManagement;
use Magento\AsynchronousOperations\Model\ResourceModel\Operation\Collection;
use Magento\AsynchronousOperations\Model\ResourceModel\Operation\CollectionFactory;
use Magento\Authorization\Model\UserContextInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\EntityManager\EntityManager;
use Magento\Framework\EntityManager\EntityMetadataInterface;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\MessageQueue\BulkPublisherInterface;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Unit test for BulkManagement model.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class BulkManagementTest extends TestCase
{
    /**
     * @var EntityManager|MockObject
     */
    private $entityManager;

    /**
     * @var BulkSummaryInterfaceFactory|MockObject
     */
    private $bulkSummaryFactory;

    /**
     * @var CollectionFactory|MockObject
     */
    private $operationCollectionFactory;

    /**
     * @var BulkPublisherInterface|MockObject
     */
    private $publisher;

    /**
     * @var MetadataPool|MockObject
     */
    private $metadataPool;

    /**
     * @var ResourceConnection|MockObject
     */
    private $resourceConnection;

    /**
     * @var LoggerInterface|MockObject
     */
    private $logger;

    /**
     * @var BulkManagement
     */
    private $bulkManagement;

    /**
     * Set up.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->entityManager = $this->createMock(EntityManager::class);
        $this->bulkSummaryFactory = $this->getMockBuilder(BulkSummaryInterfaceFactory::class)
            ->onlyMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->operationCollectionFactory = $this->getMockBuilder(CollectionFactory::class)
            ->onlyMethods(['create'])
            ->disableOriginalConstructor()
            ->getMock();
        $this->publisher = $this->getMockForAbstractClass(BulkPublisherInterface::class);
        $this->metadataPool = $this->createMock(MetadataPool::class);
        $this->resourceConnection = $this->createMock(ResourceConnection::class);
        $this->logger = $this->getMockForAbstractClass(LoggerInterface::class);

        $objectManager = new ObjectManager($this);
        $this->bulkManagement = $objectManager->getObject(
            BulkManagement::class,
            [
                'entityManager' => $this->entityManager,
                'bulkSummaryFactory' => $this->bulkSummaryFactory,
                'operationCollectionFactory' => $this->operationCollectionFactory,
                'publisher' => $this->publisher,
                'metadataPool' => $this->metadataPool,
                'resourceConnection' => $this->resourceConnection,
                'logger' => $this->logger
            ]
        );
    }

    /**
     * Test for scheduleBulk method.
     *
     * @return void
     */
    public function testScheduleBulk(): void
    {
        $bulkUuid = 'bulk-001';
        $description = 'Bulk summary description...';
        $userId = 1;
        $userType = UserContextInterface::USER_TYPE_ADMIN;
        $connectionName = 'default';
        $topicNames = ['topic.name.0', 'topic.name.1'];
        $operation = $this->getMockForAbstractClass(OperationInterface::class);
        $metadata = $this->getMockForAbstractClass(EntityMetadataInterface::class);
        $this->metadataPool->expects($this->once())->method('getMetadata')
            ->with(BulkSummaryInterface::class)
            ->willReturn($metadata);
        $metadata->expects($this->once())->method('getEntityConnectionName')->willReturn($connectionName);
        $connection = $this->getMockForAbstractClass(AdapterInterface::class);
        $this->resourceConnection->expects($this->once())
            ->method('getConnectionByName')->with($connectionName)->willReturn($connection);
        $connection->expects($this->once())->method('beginTransaction')->willReturnSelf();
        $bulkSummary = $this->getMockForAbstractClass(BulkSummaryInterface::class);
        $this->bulkSummaryFactory->expects($this->once())->method('create')->willReturn($bulkSummary);
        $this->entityManager->expects($this->once())
            ->method('load')->with($bulkSummary, $bulkUuid)->willReturn($bulkSummary);
        $bulkSummary->expects($this->once())->method('setBulkId')->with($bulkUuid)->willReturnSelf();
        $bulkSummary->expects($this->once())->method('setDescription')->with($description)->willReturnSelf();
        $bulkSummary->expects($this->once())->method('setUserId')->with($userId)->willReturnSelf();
        $bulkSummary->expects($this->once())->method('setUserType')->with($userType)->willReturnSelf();
        $bulkSummary->expects($this->once())->method('getOperationCount')->willReturn(1);
        $bulkSummary->expects($this->once())->method('setOperationCount')->with(3)->willReturnSelf();
        $this->entityManager->expects($this->once())->method('save')->with($bulkSummary)->willReturn($bulkSummary);
        $connection->expects($this->once())->method('commit')->willReturnSelf();
        $operation->expects($this->exactly(2))->method('getTopicName')
            ->willReturnOnConsecutiveCalls($topicNames[0], $topicNames[1]);
        $this->publisher->expects($this->exactly(2))->method('publish')
            ->withConsecutive([$topicNames[0], [$operation]], [$topicNames[1], [$operation]])->willReturn(null);
        $this->assertTrue(
            $this->bulkManagement->scheduleBulk($bulkUuid, [$operation, $operation], $description, $userId)
        );
    }

    /**
     * Test for scheduleBulk method with exception.
     *
     * @return void
     */
    public function testScheduleBulkWithException(): void
    {
        $bulkUuid = 'bulk-001';
        $description = 'Bulk summary description...';
        $userId = 1;
        $connectionName = 'default';
        $exceptionMessage = 'Exception message';
        $operation = $this->getMockForAbstractClass(OperationInterface::class);
        $metadata = $this->getMockForAbstractClass(EntityMetadataInterface::class);
        $this->metadataPool->expects($this->once())->method('getMetadata')
            ->with(BulkSummaryInterface::class)
            ->willReturn($metadata);
        $metadata->expects($this->once())->method('getEntityConnectionName')->willReturn($connectionName);
        $connection = $this->getMockForAbstractClass(AdapterInterface::class);
        $this->resourceConnection->expects($this->once())
            ->method('getConnectionByName')->with($connectionName)->willReturn($connection);
        $connection->expects($this->once())->method('beginTransaction')->willReturnSelf();
        $bulkSummary = $this->getMockForAbstractClass(BulkSummaryInterface::class);
        $this->bulkSummaryFactory->expects($this->once())->method('create')->willReturn($bulkSummary);
        $this->entityManager->expects($this->once())->method('load')
            ->with($bulkSummary, $bulkUuid)->willThrowException(new \LogicException($exceptionMessage));
        $connection->expects($this->once())->method('rollBack')->willReturnSelf();
        $this->logger->expects($this->once())->method('critical')->with($exceptionMessage);
        $this->publisher->expects($this->never())->method('publish');
        $this->assertFalse($this->bulkManagement->scheduleBulk($bulkUuid, [$operation], $description, $userId));
    }

    /**
     * Test for retryBulk method.
     *
     * @return void
     */
    public function testRetryBulk(): void
    {
        $bulkUuid = 'bulk-001';
        $errorCodes = ['errorCode'];
        $connectionName = 'default';
        $operationId = 0;
        $operationTable = 'magento_operation';
        $topicName = 'topic.name';
        $metadata = $this->getMockForAbstractClass(EntityMetadataInterface::class);
        $this->metadataPool->expects($this->once())->method('getMetadata')
            ->with(BulkSummaryInterface::class)
            ->willReturn($metadata);
        $metadata->expects($this->once())->method('getEntityConnectionName')->willReturn($connectionName);
        $connection = $this->getMockForAbstractClass(AdapterInterface::class);
        $this->resourceConnection->expects($this->once())
            ->method('getConnectionByName')->with($connectionName)->willReturn($connection);
        $operationCollection = $this->createMock(Collection::class);
        $this->operationCollectionFactory->expects($this->once())->method('create')->willReturn($operationCollection);
        $operationCollection->expects($this->exactly(2))->method('addFieldToFilter')
            ->withConsecutive(['error_code', ['in' => $errorCodes]], ['bulk_uuid', ['eq' => $bulkUuid]])
            ->willReturnSelf();
        $operation = $this->getMockForAbstractClass(OperationInterface::class);
        $operationCollection->expects($this->once())->method('getItems')->willReturn([$operation]);
        $connection->expects($this->once())->method('beginTransaction')->willReturnSelf();
        $operation->expects($this->once())->method('getId')->willReturn($operationId);
        $this->resourceConnection->expects($this->once())
            ->method('getTableName')->with($operationTable)->willReturn($operationTable);
        $connection
            ->method('quoteInto')
            ->withConsecutive(
                ['operation_key IN (?)', [$operationId]],
                ['bulk_uuid = ?', $bulkUuid]
            )
            ->willReturnOnConsecutiveCalls(
                'operation_key IN (' . $operationId . ')',
                "bulk_uuid = '$bulkUuid'"
            );
        $connection->expects($this->once())
            ->method('delete')
            ->with($operationTable, 'operation_key IN (' . $operationId . ') AND bulk_uuid = \'' . $bulkUuid . '\'')
            ->willReturn(1);
        $connection->expects($this->once())->method('commit')->willReturnSelf();
        $operation->expects($this->once())->method('getTopicName')->willReturn($topicName);
        $this->publisher->expects($this->once())->method('publish')->with($topicName, [$operation])->willReturn(null);
        $this->assertEquals(1, $this->bulkManagement->retryBulk($bulkUuid, $errorCodes));
    }

    /**
     * Test for retryBulk method with exception.
     *
     * @return void
     */
    public function testRetryBulkWithException(): void
    {
        $bulkUuid = 'bulk-001';
        $errorCodes = ['errorCode'];
        $connectionName = 'default';
        $operationId = 0;
        $operationTable = 'magento_operation';
        $exceptionMessage = 'Exception message';
        $metadata = $this->getMockForAbstractClass(EntityMetadataInterface::class);
        $this->metadataPool->expects($this->once())->method('getMetadata')
            ->with(BulkSummaryInterface::class)
            ->willReturn($metadata);
        $metadata->expects($this->once())->method('getEntityConnectionName')->willReturn($connectionName);
        $connection = $this->getMockForAbstractClass(AdapterInterface::class);
        $this->resourceConnection->expects($this->once())
            ->method('getConnectionByName')->with($connectionName)->willReturn($connection);
        $operationCollection = $this->createMock(Collection::class);
        $this->operationCollectionFactory->expects($this->once())->method('create')->willReturn($operationCollection);
        $operationCollection->expects($this->exactly(2))->method('addFieldToFilter')
            ->withConsecutive(['error_code', ['in' => $errorCodes]], ['bulk_uuid', ['eq' => $bulkUuid]])
            ->willReturnSelf();
        $operation = $this->getMockForAbstractClass(OperationInterface::class);
        $operationCollection->expects($this->once())->method('getItems')->willReturn([$operation]);
        $connection->expects($this->once())->method('beginTransaction')->willReturnSelf();
        $operation->expects($this->once())->method('getId')->willReturn($operationId);
        $this->resourceConnection->expects($this->once())
            ->method('getTableName')->with($operationTable)->willReturn($operationTable);
        $connection
            ->method('quoteInto')
            ->withConsecutive(
                ['operation_key IN (?)', [$operationId]],
                ['bulk_uuid = ?', $bulkUuid]
            )
            ->willReturnOnConsecutiveCalls(
                'operation_key IN (' . $operationId . ')',
                "bulk_uuid = '$bulkUuid'"
            );
        $connection->expects($this->once())
            ->method('delete')
            ->with($operationTable, 'operation_key IN (' . $operationId . ') AND bulk_uuid = \'' . $bulkUuid . '\'')
            ->willThrowException(new \Exception($exceptionMessage));
        $connection->expects($this->once())->method('rollBack')->willReturnSelf();
        $this->logger->expects($this->once())->method('critical')->with($exceptionMessage);
        $this->publisher->expects($this->never())->method('publish');
        $this->assertEquals(0, $this->bulkManagement->retryBulk($bulkUuid, $errorCodes));
    }

    /**
     * Test for deleteBulk method.
     *
     * @return void
     */
    public function testDeleteBulk(): void
    {
        $bulkUuid = 'bulk-001';
        $bulkSummary = $this->getMockForAbstractClass(BulkSummaryInterface::class);
        $this->bulkSummaryFactory->expects($this->once())->method('create')->willReturn($bulkSummary);
        $this->entityManager->expects($this->once())
            ->method('load')->with($bulkSummary, $bulkUuid)->willReturn($bulkSummary);
        $this->entityManager->expects($this->once())->method('delete')->with($bulkSummary)->willReturn(true);
        $this->assertTrue($this->bulkManagement->deleteBulk($bulkUuid));
    }
}
