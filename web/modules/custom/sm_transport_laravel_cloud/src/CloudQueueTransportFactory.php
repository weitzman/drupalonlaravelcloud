<?php

declare(strict_types=1);

namespace Drupal\sm_transport_laravel_cloud;

use Aws\Credentials\CredentialProvider;
use Aws\Sqs\SqsClient;
use Laravel\Cloud\Symfony\Queue\Agent\AgentClient;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;
use Laravel\Cloud\Symfony\Queue\Messenger\CloudQueueTransport;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Messenger\Transport\TransportFactoryInterface;
use Symfony\Component\Messenger\Transport\TransportInterface;

/**
 * Creates the managed queue transport for 'laravel-cloud://' DSNs.
 *
 * Replaces the factory in laravel/symfony-on-cloud so the SQS client gets an
 * explicit endpoint. Without one the AWS SDK uses AWS_ENDPOINT_URL, which
 * Laravel Cloud sets to the object storage endpoint when a bucket is attached.
 */
final class CloudQueueTransportFactory implements TransportFactoryInterface {

  public function __construct(
    private readonly ManagedQueueConfig $config,
    private readonly AgentClient $agent,
  ) {}

  public function createTransport(#[\SensitiveParameter] string $dsn, array $options, SerializerInterface $serializer): TransportInterface {
    return new CloudQueueTransport(
      $this->sqsClient(),
      $this->agent,
      $this->config,
      $serializer,
      $this->config->agentAvailable(),
    );
  }

  public function supports(#[\SensitiveParameter] string $dsn, array $options): bool {
    return \str_starts_with($dsn, 'laravel-cloud://');
  }

  private function sqsClient(): SqsClient {
    $args = [
      'version' => 'latest',
      'region' => $this->config->region ?? 'us-east-1',
    ];

    // The queue URL prefix is "https://sqs.REGION.amazonaws.com/ACCOUNT".
    $prefix = \parse_url($this->config->prefix);
    if (isset($prefix['scheme'], $prefix['host'])) {
      $args['endpoint'] = $prefix['scheme'] . '://' . $prefix['host'] . (isset($prefix['port']) ? ':' . $prefix['port'] : '');
    }

    if ($this->config->usesEcsCredentials()) {
      $args['credentials'] = CredentialProvider::ecsCredentials();
    }

    return new SqsClient($args);
  }

}
