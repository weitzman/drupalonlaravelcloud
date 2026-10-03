<?php

declare(strict_types=1);

namespace Drupal\sm_transport_laravel_cloud;

use Laravel\Cloud\Symfony\Observability\Events;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;

/**
 * Builds Laravel Cloud services from the variables the platform injects.
 *
 * The values are read when a service is first used, not when the container is
 * compiled, so a container built in one environment stays valid in another.
 */
final class CloudEnvironment {

  /**
   * Default address of the observability socket inside a Cloud container.
   */
  private const string LOG_SOCKET = 'unix:///tmp/cloud-init.sock';

  /**
   * Parses the managed queue configuration.
   *
   * Reports itself as not configured when the variable is absent, such as in
   * local development.
   */
  public static function managedQueueConfig(): ManagedQueueConfig {
    return ManagedQueueConfig::fromEnvironment(
      \getenv('LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG') ?: NULL,
      \getenv('LARAVEL_CLOUD_AGENT_SOCKET') ?: NULL,
    );
  }

  /**
   * Creates the emitter for dashboard events.
   */
  public static function events(): Events {
    return new Events(\getenv('LARAVEL_CLOUD_LOG_SOCKET') ?: self::LOG_SOCKET);
  }

}
