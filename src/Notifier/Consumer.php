<?php

namespace Emartech\Chunkulator\Notifier;

use Emartech\AmqpWrapper\Message;
use Emartech\AmqpWrapper\QueueConsumer;
use Emartech\Chunkulator\QueueFactory;
use Emartech\Chunkulator\Request\ChunkRequest;
use Emartech\Chunkulator\Request\ChunkRequestBuilder;
use Exception;
use Emartech\Chunkulator\Exception as ResultHandlerException;
use Psr\Log\LoggerInterface;


class Consumer implements QueueConsumer
{
    private $resultHandler;
    private $logger;
    private $queueFactory;

    /** @var Calculation[] */
    private $calculations = [];

    public function __construct(ResultHandler $resultHandler, LoggerInterface $logger, QueueFactory $queueFactory)
    {
        $this->resultHandler = $resultHandler;
        $this->logger = $logger;
        $this->queueFactory = $queueFactory;
    }

    public function getPrefetchCount(): ?int
    {
        return null;
    }

    public function consume(Message $message): void
    {
        $this->logger->debug('Start consuming', [
            'message' => $message->getRawBody()
        ]);
        $chunkRequest = ChunkRequestBuilder::fromMessage($message);
        $calculation = $this->getCalculation($chunkRequest);
        $calculation->addFinishedChunk($chunkRequest->getChunkId(), $message);
        try {
            $this->logger->debug('AddFinishedChunk success', [
                'message' => $message->getRawBody(),
                'finished_ids' => array_keys($this->calculations)
            ]);
            $calculation->finish($this);
        } catch (ResultHandlerException $ex) {
            $this->logger->error('Finishing calculation failed', ['exception' => $ex]);
            $notifierQueue = $this->queueFactory->createNotifierQueue();
            $calculation->retryNotification($notifierQueue);
            $notifierQueue->close();
        } catch (Exception $ex) {
            $this->logger->error('Finishing calculation failed', ['exception' => $ex]);
            throw $ex;
        }
    }

    public function timeOut(): void
    {
        foreach ($this->calculations as $requestId => $calculation) {
            $calculation->requeue();
            $this->removeCalculation($requestId);
        }
    }

    public function removeCalculation(string $requestId): void
    {
        unset($this->calculations[$requestId]);
    }

    private function getCalculation(ChunkRequest $chunkRequest): Calculation
    {
        $calculationRequest = $chunkRequest->getCalculationRequest();
        $requestId = $calculationRequest->getRequestId();
        if (!isset($this->calculations[$requestId])) {
            $this->calculations[$requestId] = new Calculation($this->resultHandler, $calculationRequest, $this->logger);
        }
        return $this->calculations[$requestId];
    }
}
