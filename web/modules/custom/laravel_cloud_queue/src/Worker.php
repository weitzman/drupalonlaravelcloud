<?php

declare(strict_types=1);

namespace Drupal\laravel_cloud_queue;

use Drupal\Core\Database\Connection;
use Drupal\Core\Queue\DelayedRequeueException;
use Drupal\Core\Queue\QueueWorkerManagerInterface;
use Drupal\Core\Queue\RequeueException;
use Drupal\Core\Queue\SuspendQueueException;
use Psr\Log\LoggerInterface;

/**
 * Receives items from a managed queue and runs their queue worker plugins.
 */
class Worker {

  private bool $stop = FALSE;

  public function __construct(
    private readonly CloudQueueClient $client,
    private readonly QueueWorkerManagerInterface $workerManager,
    private readonly Metrics $metrics,
    private readonly LoggerInterface $logger,
    private readonly Connection $database,
    private readonly int $maxTries,
    private readonly int $backoff,
  ) {}

  /**
   * Asks the worker to stop once the current item is finished.
   */
  public function stop(): void {
    $this->stop = TRUE;
  }

  /**
   * Processes items until a limit is reached or the worker is stopped.
   *
   * @param string|null $managedQueue
   *   The managed queue to read when not on Laravel Cloud.
   * @param int $limit
   *   Stop after this many items. 0 for no limit.
   * @param int $timeLimit
   *   Stop after this many seconds. 0 for no limit.
   * @param int $memoryLimit
   *   Stop once memory use exceeds this many megabytes. 0 for no limit.
   *
   * @return int
   *   The number of items received.
   */
  public function run(?string $managedQueue = NULL, int $limit = 0, int $timeLimit = 0, int $memoryLimit = 0): int {
    $end = $timeLimit > 0 ? \time() + $timeLimit : NULL;
    $count = 0;

    while (!$this->stop
      && ($limit === 0 || $count < $limit)
      && ($end === NULL || \time() < $end)
      && ($memoryLimit === 0 || \memory_get_usage(TRUE) < $memoryLimit * 1024 * 1024)) {
      if ($item = $this->client->receive($managedQueue)) {
        $this->process($item);
        $count++;
      }
    }

    return $count;
  }

  /**
   * Runs the queue worker plugin for one item and reports the outcome.
   */
  private function process(ReceivedItem $item): void {
    $managedQueue = $this->client->managedQueueFromUrl($item->queueUrl);
    $startedAt = \microtime(TRUE);
    $this->metrics->queue('started', $managedQueue);

    // A worker can sit idle, or be suspended by the platform, for longer than
    // the database server keeps a connection open. A connection cannot be
    // reopened in place, so hand the item back and exit; Laravel Cloud starts
    // a fresh worker, which receives the item again.
    try {
      $this->database->query('SELECT 1');
    }
    catch (\Exception $e) {
      $this->release($item, $managedQueue, $startedAt, 0);
      throw new \RuntimeException('The database connection was lost while the worker was idle. Exiting so a new worker starts.', 0, $e);
    }

    try {
      if ($item->queueName === NULL) {
        throw new \UnexpectedValueException('The message is not a Drupal queue item.');
      }
      $this->workerManager->createInstance($item->queueName)->processItem($item->data);
      $this->client->delete($item);
      $this->metrics->queue('processed', $managedQueue, $startedAt);
    }
    // The queue worker asked for the item to be tried again; not a failure.
    catch (DelayedRequeueException $e) {
      $this->release($item, $managedQueue, $startedAt, $e->getDelay());
    }
    catch (RequeueException) {
      $this->release($item, $managedQueue, $startedAt, 0);
    }
    catch (SuspendQueueException $e) {
      $this->release($item, $managedQueue, $startedAt, (int) \ceil($e->getDelay() ?? $this->retryDelay($item)));
    }
    catch (\Throwable $e) {
      if ($item->attempts < $this->maxTries) {
        $this->logger->warning('Item of queue @queue failed on attempt @attempt and will be retried: @message', [
          '@queue' => $item->queueName ?? '(unknown)',
          '@attempt' => $item->attempts,
          '@message' => $e->getMessage(),
        ]);
        $this->release($item, $managedQueue, $startedAt, $this->retryDelay($item));
        return;
      }

      $this->logger->error('Item of queue @queue failed after @attempt attempts and was removed: @message', [
        '@queue' => $item->queueName ?? '(unknown)',
        '@attempt' => $item->attempts,
        '@message' => $e->getMessage(),
      ]);
      $this->client->delete($item);
      $this->metrics->queue('failed', $managedQueue, $startedAt);
      $this->metrics->failedJob($item, $managedQueue, $startedAt, $e);
    }
  }

  private function release(ReceivedItem $item, string $managedQueue, float $startedAt, int $delay): void {
    $this->client->release($item, $delay);
    $this->metrics->queue('released', $managedQueue, $startedAt);
  }

  /**
   * Returns the seconds to wait before the next attempt, doubling each time.
   */
  private function retryDelay(ReceivedItem $item): int {
    return $this->backoff * 2 ** ($item->attempts - 1);
  }

}
