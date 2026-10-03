<?php

declare(strict_types=1);

namespace Drupal\sm_transport_laravel_cloud\EventSubscriber;

use Drupal\sm\QueueInterceptor\SmLegacyDrupalQueueItem;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueTransport;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Exception\TransportException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;

/**
 * Rejects messages too large for a managed queue with a clear error.
 *
 * Without this check SQS rejects the message and the sender only sees a
 * generic AWS error.
 */
final class PayloadSizeSubscriber implements EventSubscriberInterface {

  /**
   * The largest job payload a Laravel Cloud managed queue accepts, in bytes.
   */
  public const int MAX_BYTES = 1048576;

  public function __construct(
    private readonly SerializerInterface $serializer,
  ) {}

  public static function getSubscribedEvents(): array {
    return [SendMessageToTransportsEvent::class => 'onSend'];
  }

  public function onSend(SendMessageToTransportsEvent $event): void {
    $toCloud = FALSE;
    foreach ($event->getSenders() as $sender) {
      $toCloud = $toCloud || $sender instanceof CloudQueueTransport;
    }
    if (!$toCloud) {
      return;
    }

    $envelope = $event->getEnvelope();
    $message = $envelope->getMessage();
    $encoded = $this->serializer->encode($envelope);

    // Mirrors the wrapper CloudQueueTransport puts around each message.
    $bytes = \strlen((string) \json_encode([
      'uuid' => \str_repeat('0', 36),
      'displayName' => $message::class,
      'body' => \base64_encode($encoded['body'] ?? ''),
      'headers' => $encoded['headers'] ?? [],
    ]));

    if ($bytes > self::MAX_BYTES) {
      $subject = $message instanceof SmLegacyDrupalQueueItem
        ? \sprintf('The item for Drupal queue "%s"', $message->queueName)
        : \sprintf('The message %s', $message::class);
      throw new TransportException(\sprintf('%s is %d bytes when encoded, which exceeds the %d byte limit of Laravel Cloud managed queues. Store the data elsewhere and queue a reference to it.', $subject, $bytes, self::MAX_BYTES));
    }
  }

}
