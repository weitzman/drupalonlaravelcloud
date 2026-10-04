<?php

declare(strict_types=1);

namespace Drupal\laravelcloud_queue\Queue;

use Drupal\Core\Queue\ReliableQueueInterface;
use Drupal\laravelcloud_queue\CloudQueueClient;
use Drupal\laravelcloud_queue\Metrics;

/**
 * A Drupal queue stored in a Laravel Cloud managed queue.
 *
 * Items are processed by Laravel Cloud's managed workers through the
 * "lc:queue-work" command, which receives from the managed queue
 * and runs the matching queue worker plugin. Items cannot be claimed by
 * Drupal queue name because several Drupal queues may share one managed
 * queue, so cron and "drush queue:run" find nothing to do.
 */
class CloudQueue implements ReliableQueueInterface {

  public function __construct(
    private readonly string $name,
    private readonly CloudQueueClient $client,
    private readonly Metrics $metrics,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function createItem($data) {
    $id = $this->client->send($this->name, $data);
    $this->metrics->queue('queued', $this->client->managedQueue($this->name));
    return $id;
  }

  /**
   * {@inheritdoc}
   *
   * This is the approximate number of waiting items in the managed queue,
   * which includes items of any other Drupal queues that share it.
   */
  public function numberOfItems() {
    return $this->client->count($this->client->managedQueue($this->name));
  }

  /**
   * {@inheritdoc}
   */
  public function claimItem($lease_time = 3600) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function deleteItem($item) {
  }

  /**
   * {@inheritdoc}
   */
  public function releaseItem($item) {
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function createQueue() {
    // Managed queues are created in Laravel Cloud.
  }

  /**
   * {@inheritdoc}
   */
  public function deleteQueue() {
    // A managed queue may be shared, so it is never purged from here.
  }

}
