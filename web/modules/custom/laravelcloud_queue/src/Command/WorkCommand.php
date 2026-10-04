<?php

declare(strict_types=1);

namespace Drupal\laravelcloud_queue\Command;

use Drupal\laravelcloud_queue\Worker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Processes Drupal queue items from a Laravel Cloud managed queue.
 */
#[AsCommand(
  name: 'lc:queue-work',
  description: 'Processes Drupal queue items from a Laravel Cloud managed queue.',
)]
class WorkCommand extends Command {

  public function __construct(
    private readonly Worker $worker,
  ) {
    parent::__construct();
  }

  protected function configure(): void {
    $this
      ->addOption('queues', NULL, InputOption::VALUE_REQUIRED, 'The managed queue to read when not on Laravel Cloud, where the worker is bound to its own queue.')
      ->addOption('limit', NULL, InputOption::VALUE_REQUIRED, 'Stop after this many items.', '0')
      ->addOption('time-limit', NULL, InputOption::VALUE_REQUIRED, 'Stop after this many seconds.', '0')
      ->addOption('memory-limit', NULL, InputOption::VALUE_REQUIRED, 'Stop once memory use exceeds this many megabytes.', '0');
  }

  protected function execute(InputInterface $input, OutputInterface $output): int {
    // Finish the current item when the platform asks the worker to stop.
    if (\function_exists('pcntl_signal')) {
      \pcntl_async_signals(TRUE);
      foreach ([SIGTERM, SIGINT, SIGQUIT] as $signal) {
        \pcntl_signal($signal, fn () => $this->worker->stop());
      }
    }

    $count = $this->worker->run(
      $input->getOption('queues'),
      (int) $input->getOption('limit'),
      (int) $input->getOption('time-limit'),
      (int) $input->getOption('memory-limit'),
    );
    $output->writeln(\sprintf('Processed %d items.', $count));

    return Command::SUCCESS;
  }

}
