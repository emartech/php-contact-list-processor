<?php

namespace Emartech\Chunkulator\Test\Calculator;

use Emartech\Chunkulator\QueueFactory;
use Emartech\TestHelper\BaseTestCase;
use Emartech\Chunkulator\Calculator\Consumer;
use Emartech\Chunkulator\Calculator\ContactListHandler;
use Emartech\Chunkulator\Calculator\Filter;
use Emartech\Chunkulator\Notifier\ResultHandler;
use Emartech\Chunkulator\Request\ChunkRequest;
use Emartech\Chunkulator\Test\Helpers\CalculationRequest;
use Emartech\Chunkulator\Test\Helpers\Constants;
use Interop\Amqp\AmqpConsumer;
use Interop\Amqp\AmqpContext;
use Interop\Amqp\AmqpProducer;
use Interop\Amqp\AmqpQueue;
use Interop\Queue\Message;
use Interop\Queue\Processor;
use PHPUnit\Framework\MockObject\Builder\InvocationMocker;
use PHPUnit\Framework\MockObject\MockObject;
use Throwable;

class ConsumerTest extends BaseTestCase
{
    /**
     * @var Consumer
     */
    private $consumer;

    /**
     * @var ResultHandler|MockObject
     */
    private $resultHandler;

    /**
     * @var Queue|MockObject
     */
    private $workerQueue;

    /**
     * @var Queue|MockObject
     */
    private $notificationQueue;

    /**
     * @var Filter|MockObject
     */
    private $filter;

    /**
     * @var ContactListHandler|MockObject
     */
    private $contactListHandler;

    /**
     * @var AmqpContext|MockObject
     */
    private $context;
    /**
     * @var AmqpProducer|MockObject
     */
    private $producer;
    /**
     * @var AmqpConsumer|MockObject
     */
    private $amqpConsumer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->contactListHandler = $this->createMock(ContactListHandler::class);
        $this->filter = $this->createMock(Filter::class);
        $this->resultHandler = $this->createMock(ResultHandler::class);

        $this->consumer = new Consumer(
            $this->contactListHandler,
            $this->filter,
            $this->resultHandler,
            $this->mockQueues()
        );
    }

    /**
     * @test
     */
    public function consume_userListIdGivenThirdChunk_getUserListChunkFromSuiteAndEnqueueToNotifier()
    {
        $contactIds = [1, 2, 3];
        $filteredContactIds = [2];
        $chunkCount = 5;

        $request = CalculationRequest::createChunkRequest($chunkCount, 3, 2);
        $message = $this->createMessage($request);

        $this->expectSourceContactsToBe($contactIds, Constants::REQUEST_DATA, 3, 6);

        $this->expectFiltering()
            ->with(Constants::REQUEST_DATA, $contactIds)
            ->willReturn($filteredContactIds);

        $this->expectTargetContactToBe(Constants::REQUEST_DATA, $filteredContactIds);

        $this->expectEnqueueToNotifierQueue([
            'requestId' => Constants::TRIGGER_ID,
            'chunkCount' => $chunkCount,
            'chunkId' => 2,
        ]);

        $this->assertEquals(Processor::ACK, $this->consumer->process($message, $this->context));
    }

    /**
     * @test
     */
    public function consume_MoreTriesLeft_DecreaseTriesAndRequeue()
    {
        $request = CalculationRequest::createChunkRequest(1, 1, 0, 1);
        $message = $this->createMessage($request);

        $this->expectFiltering()->willThrowException($this->createMock(Throwable::class));
        $this->expectEnqueueToWorkerQueue(); // ->with($this->structure(['tries' => 0]));

        $this->resultHandler->expects($this->never())->method('onFailure');

        $this->assertEquals(Processor::REJECT, $this->consumer->process($message, $this->context));
    }

    /**
     * @test
     */
    public function consume_NoMoreTriesLeft_DiscardsMessage()
    {
        $request = CalculationRequest::createChunkRequest(2, 1, 0, 0);
        $message = $this->createMessage($request);

        $this->expectFiltering()->willThrowException($this->createMock(Throwable::class));
        $this->expectFailureHandlerCall();

        $this->assertEquals(Processor::REJECT, $this->consumer->process($message, $this->context));
    }

    /**
     * @test
     */
    public function consume_NoMoreTriesLeftCallbackFails_Requeue()
    {
        $request = CalculationRequest::createChunkRequest(2, 1, 0, 0);
        $message = $this->createMessage($request);

        $this->expectFiltering()->willThrowException($this->createMock(Throwable::class));
        $this->expectFailureHandlerCall()->willThrowException($this->createMock(Throwable::class));

        $this->expectEnqueueToWorkerQueue()
            /* TODO: fix assert
            ->with($this->structure([
                'tries' => 0
            ]))*/
            ;

        try {
            $this->consumer->process($message, $this->context);
        } catch (Throwable $t) {
            return;
        }

        $this->fail();
    }

    /**
     * @return MockObject|Message
     */
    private function createMessage(ChunkRequest $chunkRequest)
    {
        $message = $this->createMock(Message::class);
        $message
            ->expects($this->any())
            ->method('getBody')
            ->willReturn($chunkRequest->toJson());

        return $message;
    }

    private function expectEnqueueToNotifierQueue($expectedQueueItem)
    {
        $this->producer
            ->expects($this->once())
            ->method('send')
            ->with($this->notificationQueue, $this->isInstanceOf(Message::class));
        //TODO: assert $expectedQueueItem
    }

    private function expectSourceContactsToBe(array $contactIds, array $requestData, int $limit, int $offset): void
    {
        $this->contactListHandler
            ->expects($this->once())
            ->method('getContactsOfList')
            ->with($requestData, $limit, $offset)
            ->willReturn($contactIds);
    }

    private function expectTargetContactToBe(array $requestData, array $contactIds): void
    {
        $this->contactListHandler
            ->expects($this->once())
            ->method('applyContactsToList')
            ->with($requestData, $contactIds);
    }

    private function expectFailureHandlerCall(): InvocationMocker
    {
        return $this->resultHandler->expects($this->once())->method('onFailure');
    }

    private function expectFiltering(): InvocationMocker
    {
        return $this->filter->expects($this->once())->method('filterContacts');
    }

    private function expectEnqueueToWorkerQueue(): InvocationMocker
    {
        return $this->producer->expects($this->once())->method('send');
    }

    private function mockQueues(): QueueFactory
    {
        $this->notificationQueue = $this->createMock(AmqpQueue::class);
        $this->workerQueue = $this->createMock(AmqpQueue::class);
        $this->context = $this->createMock(AmqpContext::class);
        $this->producer = $this->createMock(AmqpProducer::class);
        $this->amqpConsumer = $this->createMock(AmqpConsumer::class);

        $queueFactory = $this->createMock(QueueFactory::class);
        $queueFactory
            ->expects($this->any())
            ->method('createNotifierQueue')
            ->willReturn($this->notificationQueue);

        $queueFactory
            ->expects($this->any())
            ->method('createWorkerQueue')
            ->willReturn($this->workerQueue);

        $queueFactory
            ->expects($this->any())
            ->method('createContext')
            ->willReturn($this->context);

        $this->context
            ->expects($this->any())
            ->method('createProducer')
            ->willReturn($this->producer);

        $this->context
            ->expects($this->any())
            ->method('createConsumer')
            ->willReturn($this->amqpConsumer);

        return $queueFactory;
    }
}
