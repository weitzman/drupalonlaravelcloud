<?php

declare(strict_types=1);

namespace Drupal\sm_transport_laravel_cloud;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Provides a ready-made 'cloud' transport for Laravel Cloud Managed Queues.
 *
 * Laravel Cloud starts its workers with 'messenger:consume cloud', so the
 * transport must exist under that name. A site that defines its own 'cloud'
 * transport in sm.transports keeps it.
 */
final class CloudTransportCompilerPass implements CompilerPassInterface {

  public function process(ContainerBuilder $container): void {
    if (!$container->hasParameter('sm.transports')) {
      return;
    }

    /** @var array<string, array<mixed>> $transports */
    $transports = $container->getParameter('sm.transports');
    $transports += ['cloud' => ['dsn' => 'laravel-cloud://managed-queue']];
    $container->setParameter('sm.transports', $transports);
  }

}
