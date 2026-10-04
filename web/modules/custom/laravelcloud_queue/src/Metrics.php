<?php

declare(strict_types=1);

namespace Drupal\laravelcloud_queue;

use Laravel\Cloud\Symfony\Observability\Events;
use Laravel\Cloud\Symfony\Queue\ManagedQueueConfig;
use Symfony\Component\Uid\Uuid;

/**
 * Reports queue activity and failed jobs to the Laravel Cloud dashboard.
 *
 * Events are written to the log socket inside a Cloud container. Outside
 * Laravel Cloud the socket does not exist and nothing is reported.
 */
class Metrics {

  /**
   * Default address of the log socket inside a Cloud container.
   */
  private const string LOG_SOCKET = 'unix:///tmp/cloud-init.sock';

  /**
   * Byte limits that keep a failed job event within one log line.
   *
   * Laravel Cloud's log collector drops events longer than 16 KiB. These match
   * the limits laravel/symfony-on-cloud applies for the same reason.
   */
  private const int MAX_EXCEPTION_BYTES = 4000;
  private const int MAX_BODY_BYTES = 2000;

  private Events|false|null $events = NULL;

  public function __construct(
    private readonly ManagedQueueConfig $config,
  ) {}

  /**
   * Records a queue lifecycle event.
   *
   * @param string $type
   *   One of 'queued', 'started', 'processed', 'released' or 'failed'.
   * @param string $managedQueue
   *   The managed queue name.
   * @param float|null $startedAt
   *   When processing started, as a Unix timestamp, to report a duration.
   */
  public function queue(string $type, string $managedQueue, ?float $startedAt = NULL): void {
    $now = \microtime(TRUE);
    $this->emit([
      '_cloud_event' => 'queue',
      'timestamp' => $this->format($now),
      'type' => $type,
      'queue' => $managedQueue,
    ] + ($startedAt === NULL ? [] : ['duration_ms' => (int) \round(($now - $startedAt) * 1000)]));
  }

  /**
   * Records an item that will not be retried, for the failed jobs list.
   */
  public function failedJob(ReceivedItem $item, string $managedQueue, float $startedAt, \Throwable $exception): void {
    $payload = \json_decode($item->body, TRUE);
    if (\is_array($payload) && \strlen((string) ($payload['body'] ?? '')) > self::MAX_BODY_BYTES) {
      $payload['body'] = \substr($payload['body'], 0, self::MAX_BODY_BYTES);
      $payload['body_truncated'] = TRUE;
    }

    $this->emit([
      '_cloud_event' => 'failed_job',
      // A time-ordered UUID, as laravel/symfony-on-cloud sends.
      'id' => (string) Uuid::v7(),
      'queue' => $managedQueue,
      'started_at' => $this->format($startedAt),
      'attempts' => $item->attempts,
      'payload' => \is_array($payload) ? (string) \json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : \substr($item->body, 0, self::MAX_BODY_BYTES),
      'exception' => \mb_strcut((string) \mb_convert_encoding((string) $exception, 'UTF-8'), 0, self::MAX_EXCEPTION_BYTES, 'UTF-8'),
    ]);
  }

  private function emit(array $event): void {
    if ($this->events === NULL) {
      $address = \getenv('LARAVEL_CLOUD_LOG_SOCKET') ?: self::LOG_SOCKET;
      $path = \str_starts_with($address, 'unix://') ? \substr($address, 7) : NULL;
      $this->events = $this->config->isConfigured() && ($path === NULL || \file_exists($path)) ? new Events($address) : FALSE;
    }
    if ($this->events) {
      $this->events->emit($event);
    }
  }

  private function format(float $timestamp): string {
    return \DateTimeImmutable::createFromFormat('U.u', \sprintf('%.6F', $timestamp))->format('Y-m-d H:i:s.u');
  }

}
