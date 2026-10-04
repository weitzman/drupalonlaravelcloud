<?php

declare(strict_types=1);

namespace Drupal\laravelcloud\Command;

use Cron\CronExpression;
use Drupal\Core\State\StateInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Runs Drupal cron on a schedule, until stopped.
 */
#[AsCommand(
  name: 'lc:cron',
  description: 'Runs Drupal cron on a schedule, until stopped. For a Laravel Cloud background process.',
)]
class CronCommand {

  public function __construct(
    protected StateInterface $state,
  ) {}

  public function __invoke(OutputInterface $output): int {
    // With "off" the process idles. It must not exit: Laravel Cloud restarts a
    // background process that exits, then stops the cluster if it keeps exiting.
    $expression = getenv('DRUPAL_CRON_SCHEDULE') ?: '0 * * * *';
    $schedule = $expression === 'off' ? NULL : new CronExpression($expression);
    $last = (int) $this->state->get('system.cron_last');

    while (TRUE) {
      // Run when a scheduled time has passed since the last run. That covers
      // the current minute and a time missed while the environment was asleep.
      if ($schedule && $last < $schedule->getPreviousRunDate('now', 0, TRUE, 'UTC')->getTimestamp()) {
        $last = time();
        // Composer's vendor/bin/dr sets its directory; argv may be relative.
        (new Process([PHP_BINARY, $GLOBALS['_composer_bin_dir'] . '/dr', 'system:cron']))
          ->setTimeout(NULL)
          ->run(fn (string $type, string $buffer) => $output->write($buffer));
      }
      sleep(60);
    }
  }

}
