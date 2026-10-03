<?php

declare(strict_types=1);

namespace Drupal\sm_transport_laravel_cloud;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderInterface;

/**
 * Service provider for the Laravel Cloud transport.
 */
final class SmTransportLaravelCloudServiceProvider implements ServiceProviderInterface {

  public function register(ContainerBuilder $container): void {
    $container
      // Priority 10 to execute before SmCompilerPass, which has priority 1 and
      // turns the transports parameter into services.
      ->addCompilerPass(new CloudTransportCompilerPass(), priority: 10);
  }

}
