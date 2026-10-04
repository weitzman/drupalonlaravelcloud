<?php

declare(strict_types=1);

namespace Drupal\laravelcloud_queue\Queue;

use Drupal\Core\Queue\QueueFactoryInterface;
use Drupal\laravelcloud_queue\CloudQueueClient;
use Drupal\laravelcloud_queue\Metrics;

/**
 * Creates queues backed by Laravel Cloud managed queues.
 */
class CloudQueueFactory implements QueueFactoryInterface {

  public function __construct(
    private readonly CloudQueueClient $client,
    private readonly Metrics $metrics,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function get($name): CloudQueue {
    return new CloudQueue($name, $this->client, $this->metrics);
  }

}
