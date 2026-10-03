<?php

declare(strict_types=1);

namespace Drupal\sm_transport_laravel_cloud\EventSubscriber;

use Drupal\sm\QueueInterceptor\SmLegacyDrupalQueueItem;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueStamp;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;

/**
 * Sends Drupal queue items to the managed queue their Drupal queue maps to.
 *
 * Unmapped Drupal queues use the environment's default managed queue.
 */
final class QueueMapSubscriber implements EventSubscriberInterface {

  /**
   * @param array<string, string> $queueMap
   *   Managed queue names, keyed by Drupal queue name.
   */
  public function __construct(
    private readonly array $queueMap,
  ) {}

  public static function getSubscribedEvents(): array {
    // Runs first so the dashboard's "queued" metric, emitted by a later
    // subscriber, is attributed to the mapped queue.
    return [SendMessageToTransportsEvent::class => ['onSend', 100]];
  }

  public function onSend(SendMessageToTransportsEvent $event): void {
    $envelope = $event->getEnvelope();
    $message = $envelope->getMessage();

    if (!$message instanceof SmLegacyDrupalQueueItem
      || !isset($this->queueMap[$message->queueName])
      || $envelope->last(CloudQueueStamp::class) !== NULL) {
      return;
    }

    $event->setEnvelope($envelope->with(new CloudQueueStamp($this->queueMap[$message->queueName])));
  }

}
