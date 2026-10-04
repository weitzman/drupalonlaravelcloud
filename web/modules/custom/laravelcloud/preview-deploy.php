<?php

/**
 * @file
 * Deploy command for Laravel Cloud preview environments.
 *
 * Run from the project root:
 * "php web/modules/custom/laravelcloud/preview-deploy.php".
 *
 * A preview starts with an empty database. When the database has no tables,
 * this imports the backup at DB_BACKUP_URL, which "dr lc:db-backup" uploads,
 * or without that variable installs Drupal from the config directory. Then it
 * runs "drush deploy". With STAGE_FILE_PROXY_ORIGIN set, it also makes the
 * preview fetch missing public files from that origin.
 */

// Cloud delivers variables in .env, which the project's autoloader reads.
require 'vendor/autoload.php';

$drush = function (string $command): void {
  passthru('vendor/bin/drush ' . $command, $status);
  $status === 0 || exit($status);
};

exec("vendor/bin/drush sql:query 'SHOW TABLES'", $tables, $status);
$status === 0 || exit($status);

if ($empty = !array_filter($tables)) {
  if (getenv('DB_BACKUP_URL')) {
    [$client, $object] = laravelcloud_db_backup();
    $file = sys_get_temp_dir() . '/db.sql.gz';
    $client->getObject($object + ['SaveAs' => $file]);
    $drush('sql:query --file=' . escapeshellarg($file));
  }
  else {
    $drush('site:install --existing-config --yes');
  }
}
$drush('deploy');

if ($empty && getenv('STAGE_FILE_PROXY_ORIGIN')) {
  // The backup lists the source bucket's files; this bucket has none of them.
  $drush("sql:query 'TRUNCATE s3fs_file'");
  $drush('pm:install --yes stage_file_proxy s3fs_file_proxy_to_s3');
}
