#!/usr/bin/env bash

# Deploy command for Laravel Cloud preview environments, which start with an
# empty database: "bash web/modules/custom/laravelcloud/preview-deploy.sh".
#
# When the database has no tables, copies the database at
# PREVIEW_SOURCE_DATABASE_URL, or without that variable installs Drupal from the
# config directory. Then runs "drush deploy".

set -euo pipefail
drush=vendor/bin/drush

tables=$($drush sql:query 'SHOW TABLES')
if [ -z "$tables" ]; then
  # Cloud delivers variables in .env, not the process environment. PHP sees
  # them once the project's autoloader has read that file.
  source=$(php -r 'require "vendor/autoload.php"; echo getenv("PREVIEW_SOURCE_DATABASE_URL");')
  if [ -n "$source" ]; then
    $drush sql:dump --db-url="$source" | $drush sql:cli
  else
    $drush site:install --existing-config --yes
  fi
fi
$drush deploy
