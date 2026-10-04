<?php

declare(strict_types=1);

namespace Drupal\laravelcloud_queue\Command;

use Drupal\laravelcloud_queue\Worker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Processes Drupal queue items from a Laravel Cloud managed queue.
 */
#[AsCommand(
  name: 'lc:queue-work',
  description: 'Processes Drupal queue items from a Laravel Cloud managed queue.',
)]
class WorkCommand {

  public function __construct(
    private readonly Worker $worker,
  ) {}

  public function __invoke(
    OutputInterface $output,
    #[Option(description: 'The managed queue to read. Ignored on Laravel Cloud, which assigns each worker its queue.', name: 'queues')]
    ?string $queues = NULL,
    #[Option(description: 'Stop after this many items.', name: 'limit')]
    int $limit = 0,
    #[Option(description: 'Stop after this many seconds.', name: 'time-limit')]
    int $timeLimit = 0,
    #[Option(description: 'Stop once memory use exceeds this many megabytes.', name: 'memory-limit')]
    int $memoryLimit = 0,
  ): int {
    // Finish the current item when the platform asks the worker to stop.
    if (\function_exists('pcntl_signal')) {
      \pcntl_async_signals(TRUE);
      foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
        \pcntl_signal($signal, fn () => $this->worker->stop());
      }
    }

    $count = $this->worker->run($queues, $limit, $timeLimit, $memoryLimit);
    $output->writeln(\sprintf('Processed %d items.', $count));

    return Command::SUCCESS;
  }

}
