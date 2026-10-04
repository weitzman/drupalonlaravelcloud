<?php

declare(strict_types=1);

namespace Drupal\laravelcloud\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

/**
 * Uploads a dump of the database to the bucket at DB_BACKUP_URL.
 */
#[AsCommand(
  name: 'lc:db-backup',
  description: 'Uploads a dump of the database to the bucket at DB_BACKUP_URL, replacing the previous one.',
)]
class DbBackupCommand {

  public function __invoke(OutputInterface $output): int {
    [$client, $object] = laravelcloud_db_backup();
    $file = sys_get_temp_dir() . '/db.sql';

    // Drush appends ".gz". Cache, session and log tables are dumped empty.
    (new Process([
      $GLOBALS['_composer_bin_dir'] . '/drush',
      'sql:dump',
      '--gzip',
      '--result-file=' . $file,
      '--structure-tables-list=cache,cache_*,sessions,watchdog',
    ]))->setTimeout(NULL)->mustRun();
    $client->putObject($object + ['SourceFile' => $file . '.gz']);
    unlink($file . '.gz');

    $output->writeln('Uploaded the database backup.');
    return Command::SUCCESS;
  }

}
