<?php

/**
 * @file
 * Deploy command for Laravel Cloud preview environments.
 *
 * Run from the project root:
 * "php web/modules/custom/laravelcloud/preview-deploy.php".
 *
 * A preview starts with an empty database. When the database has no tables,
 * this copies the database at PREVIEW_SOURCE_DATABASE_URL, or without that
 * variable installs Drupal from the config directory. Then it runs
 * "drush deploy".
 */

// Cloud delivers variables in .env, which the project's autoloader reads.
require 'vendor/autoload.php';

$drush = function (string $command): void {
  passthru('vendor/bin/drush ' . $command, $status);
  $status === 0 || exit($status);
};

exec("vendor/bin/drush sql:query 'SHOW TABLES'", $tables, $status);
$status === 0 || exit($status);

if (!array_filter($tables)) {
  if ($source = getenv('PREVIEW_SOURCE_DATABASE_URL')) {
    $file = sys_get_temp_dir() . '/preview-source.sql';
    $drush('sql:dump --db-url=' . escapeshellarg($source) . ' --result-file=' . escapeshellarg($file));
    $drush('sql:query --file=' . escapeshellarg($file));
  }
  else {
    $drush('site:install --existing-config --yes');
  }
}
$drush('deploy');
