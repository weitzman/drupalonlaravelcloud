<?php

declare(strict_types=1);

namespace Drupal\laravelcloud_queue;

/**
 * A queue item received from a managed queue.
 */
final class ReceivedItem {

  /**
   * @param string $messageId
   *   The SQS message ID.
   * @param string|null $receiptHandle
   *   The SQS receipt handle.
   * @param string $queueUrl
   *   The URL of the SQS queue the message came from.
   * @param bool $fromAgent
   *   Whether the Cloud agent delivered the message.
   * @param int $attempts
   *   How many times the message has been delivered, including this time.
   * @param string $body
   *   The raw message body.
   * @param string|null $queueName
   *   The Drupal queue name, or NULL if the body could not be decoded.
   * @param mixed $data
   *   The data passed to createItem().
   */
  public function __construct(
    public readonly string $messageId,
    public readonly ?string $receiptHandle,
    public readonly string $queueUrl,
    public readonly bool $fromAgent,
    public readonly int $attempts,
    public readonly string $body,
    public readonly ?string $queueName,
    public readonly mixed $data,
  ) {}

}
