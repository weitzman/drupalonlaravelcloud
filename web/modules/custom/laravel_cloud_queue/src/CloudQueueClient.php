<?php

declare(strict_types=1);

namespace Drupal\laravel_cloud_queue;

use Aws\Credentials\CredentialProvider;
use Aws\Sqs\SqsClient;
use Drupal\Component\Uuid\UuidInterface;
use Laravel\Cloud\Symfony\Queue\Agent\AgentClient;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;

/**
 * Sends queue items to, and receives them from, Laravel Cloud managed queues.
 *
 * Sending always goes to SQS. On Laravel Cloud, receiving goes through the
 * agent that runs beside each worker: it hands over the next message and
 * performs the SQS delete or visibility change once told the outcome. Without
 * the agent, such as in local development, this talks to SQS for both.
 */
class CloudQueueClient {

  /**
   * The largest message a managed queue accepts, in bytes.
   */
  public const int MAX_BYTES = 1048576;

  /**
   * The longest SQS allows a message to stay invisible, in seconds.
   */
  private const int MAX_VISIBILITY = 43200;

  private ?SqsClient $sqs = NULL;

  /**
   * @param array<string, string> $queueMap
   *   Managed queue names, keyed by Drupal queue name.
   */
  public function __construct(
    private readonly ManagedQueueConfig $config,
    private readonly AgentClient $agent,
    private readonly UuidInterface $uuid,
    private readonly array $queueMap,
  ) {}

  /**
   * Parses the managed queue configuration Laravel Cloud injects.
   */
  public static function configFromEnvironment(): ManagedQueueConfig {
    return ManagedQueueConfig::fromEnvironment(
      \getenv('LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG') ?: NULL,
      \getenv('LARAVEL_CLOUD_AGENT_SOCKET') ?: NULL,
    );
  }

  /**
   * Returns the name of the managed queue a Drupal queue is sent to.
   */
  public function managedQueue(string $drupalQueue): string {
    return $this->queueMap[$drupalQueue] ?? $this->config->queue;
  }

  /**
   * Sends an item to the managed queue for a Drupal queue.
   *
   * @return string
   *   The SQS message ID.
   */
  public function send(string $drupalQueue, mixed $data): string {
    $managedQueue = $this->managedQueue($drupalQueue);

    // "uuid" and "displayName" are what the Cloud dashboard names a job by.
    $body = \json_encode([
      'uuid' => $this->uuid->generate(),
      'displayName' => $drupalQueue,
      'body' => \base64_encode(\serialize($data)),
    ], JSON_THROW_ON_ERROR);

    if (\strlen($body) > self::MAX_BYTES) {
      throw new \LengthException(\sprintf('The item for Drupal queue "%s" is %d bytes when encoded, which exceeds the %d byte limit of Laravel Cloud managed queues. Store the data elsewhere and queue a reference to it.', $drupalQueue, \strlen($body), self::MAX_BYTES));
    }

    $args = ['QueueUrl' => $this->queueUrl($managedQueue), 'MessageBody' => $body];
    if (ManagedQueueConfig::isFifo($managedQueue)) {
      // Items of one Drupal queue stay in order relative to each other.
      $args['MessageGroupId'] = $drupalQueue;
      $args['MessageDeduplicationId'] = $this->uuid->generate();
    }

    return (string) $this->sqs()->sendMessage($args)->get('MessageId');
  }

  /**
   * Waits for the next item.
   *
   * @param string|null $managedQueue
   *   The managed queue to read when not on Laravel Cloud. There, the agent is
   *   bound to the worker's own queue and this is ignored.
   *
   * @return \Drupal\laravel_cloud_queue\ReceivedItem|null
   *   The item, or NULL if none arrived before the wait ended.
   */
  public function receive(?string $managedQueue = NULL): ?ReceivedItem {
    if ($this->config->agentAvailable()) {
      $message = $this->agent->next();
      if (!\is_string($message['messageId'] ?? NULL) || $message['messageId'] === '') {
        return NULL;
      }
      return $this->item(
        $message['messageId'],
        \is_string($message['receiptHandle'] ?? NULL) ? $message['receiptHandle'] : NULL,
        \is_string($message['queueUrl'] ?? NULL) && $message['queueUrl'] !== '' ? $message['queueUrl'] : (string) $this->config->queueUrl,
        TRUE,
        (array) ($message['attributes'] ?? []),
        \is_string($message['body'] ?? NULL) ? $message['body'] : '',
      );
    }

    $queueUrl = $this->queueUrl($managedQueue ?? $this->config->queue);
    $message = $this->sqs()->receiveMessage([
      'QueueUrl' => $queueUrl,
      'MaxNumberOfMessages' => 1,
      'WaitTimeSeconds' => 20,
      'AttributeNames' => ['ApproximateReceiveCount'],
    ])->get('Messages')[0] ?? NULL;
    if ($message === NULL) {
      return NULL;
    }
    return $this->item(
      (string) ($message['MessageId'] ?? ''),
      isset($message['ReceiptHandle']) ? (string) $message['ReceiptHandle'] : NULL,
      $queueUrl,
      FALSE,
      (array) ($message['Attributes'] ?? []),
      (string) ($message['Body'] ?? ''),
    );
  }

  /**
   * Removes a finished item from its queue.
   */
  public function delete(ReceivedItem $item): void {
    if ($item->fromAgent) {
      $this->agent->report($item->messageId, $item->receiptHandle, 'processed');
      return;
    }
    $this->sqs()->deleteMessage(['QueueUrl' => $item->queueUrl, 'ReceiptHandle' => $item->receiptHandle]);
  }

  /**
   * Returns an item to its queue, to be delivered again after a delay.
   */
  public function release(ReceivedItem $item, int $delay): void {
    $delay = \max(0, \min(self::MAX_VISIBILITY, $delay));
    if ($item->fromAgent) {
      $this->agent->report($item->messageId, $item->receiptHandle, 'released', $delay);
      return;
    }
    $this->sqs()->changeMessageVisibility([
      'QueueUrl' => $item->queueUrl,
      'ReceiptHandle' => $item->receiptHandle,
      'VisibilityTimeout' => $delay,
    ]);
  }

  /**
   * Returns the approximate number of waiting messages in a managed queue.
   */
  public function count(string $managedQueue): int {
    $attributes = $this->sqs()->getQueueAttributes([
      'QueueUrl' => $this->queueUrl($managedQueue),
      'AttributeNames' => ['ApproximateNumberOfMessages'],
    ])->get('Attributes');
    return (int) ($attributes['ApproximateNumberOfMessages'] ?? 0);
  }

  /**
   * Returns the managed queue name for an SQS queue URL.
   */
  public function managedQueueFromUrl(string $queueUrl): string {
    return $this->config->normalizeQueue($queueUrl);
  }

  private function item(string $messageId, ?string $receiptHandle, string $queueUrl, bool $fromAgent, array $attributes, string $body): ReceivedItem {
    // A message this module did not send has no queue name and fails in the
    // worker like any other item.
    $decoded = \json_decode($body, TRUE);
    $serialized = \is_array($decoded) && \is_string($decoded['body'] ?? NULL) ? \base64_decode($decoded['body'], TRUE) : FALSE;
    $data = $serialized === FALSE ? FALSE : @\unserialize($serialized);
    $valid = \is_string($decoded['displayName'] ?? NULL) && ($data !== FALSE || $serialized === \serialize(FALSE));
    return new ReceivedItem(
      $messageId,
      $receiptHandle,
      $queueUrl,
      $fromAgent,
      \max(1, (int) ($attributes['ApproximateReceiveCount'] ?? 1)),
      $body,
      $valid ? $decoded['displayName'] : NULL,
      $valid ? $data : NULL,
    );
  }

  private function queueUrl(string $managedQueue): string {
    return $this->config->queueUrlFor($managedQueue)
      ?? throw new \RuntimeException('No Laravel Cloud managed queue is configured: LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG is not set.');
  }

  private function sqs(): SqsClient {
    if ($this->sqs === NULL) {
      $args = ['version' => 'latest', 'region' => $this->config->region ?? 'us-east-1'];

      // Pin the endpoint to the host of the queue URL prefix. Otherwise the
      // AWS SDK uses AWS_ENDPOINT_URL, which Laravel Cloud sets to the object
      // storage endpoint when a bucket is attached.
      $prefix = \parse_url($this->config->prefix);
      if (isset($prefix['scheme'], $prefix['host'])) {
        $args['endpoint'] = $prefix['scheme'] . '://' . $prefix['host'] . (isset($prefix['port']) ? ':' . $prefix['port'] : '');
      }
      if ($this->config->usesEcsCredentials()) {
        $args['credentials'] = CredentialProvider::ecsCredentials();
      }
      $this->sqs = new SqsClient($args);
    }
    return $this->sqs;
  }

}
