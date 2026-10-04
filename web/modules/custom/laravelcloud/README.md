# Laravel Cloud

Host a Drupal site on [Laravel Cloud](https://cloud.laravel.com): settings
from Cloud's environment variables, cron, database backups and preview
environments. Works with an environment that scales to zero.

## Features

- **Settings:** `laravelcloud_settings()` configures the database, hash salt,
  site URL, reverse proxy and s3fs file storage from Cloud's environment
  variables. It does nothing off Cloud.
- **Cron:** `dr lc:cron` runs Drupal cron on a schedule from a Cloud
  background process.
- **Database backup:** `dr lc:db-backup` uploads a gzipped dump to a private
  bucket, for preview environments and CI.
- **Preview environments:** `preview-deploy.php` fills a new preview's empty
  database from the backup, or installs from config, then runs `drush deploy`.

## Post-Installation

### Project

- Cloud serves `public/`. Commit a symlink: `ln -s web public`.
- Commit `composer.lock`.
- Build command:
  `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader`
- Deploy command: `vendor/bin/drush deploy`
- Cloud writes environment variables to `.env` in the project root, not to
  the process environment. Load it from a script listed under
  `autoload.files` in the site's `composer.json`:

  ```php
  if (file_exists(__DIR__ . '/.env')) {
    (new \Symfony\Component\Dotenv\Dotenv())->usePutenv()->load(__DIR__ . '/.env');
  }
  ```

### Settings

Add to the end of `settings.php`:

```php
laravelcloud_settings($settings, $databases, $config);
```

| Setting | Source |
| --- | --- |
| Database | `DATABASE_URL` (MySQL) |
| Hash salt | `APP_SECRET`. Add it as a custom environment variable. |
| Site URL | `DEFAULT_URI` or `APP_URL`, copied to `DRUSH_OPTIONS_URI` and `DRUPAL_URI` |
| Reverse proxy | Cloud's proxy is trusted |
| Files | `AWS_*`, when a bucket is attached |

Trusted hosts are not set. Cloud rejects requests for other hosts; set
`$settings['trusted_host_patterns']` yourself to clear the status report
warning.

### Files

Instances have no persistent disk. To store public files in a bucket:

1. `composer require drupal/s3fs` and enable it.
2. In the Cloud dashboard, create a bucket with public visibility and attach
   it to the environment.

Files are served from `https://<AWS_BUCKET>.laravel.cloud`. Set `AWS_URL` as a
custom environment variable to use another base URL.

### Cron

1. Uninstall `automated_cron`, enable this module and deploy.
2. Add a custom environment variable with a cron expression in UTC, for
   example `DRUPAL_CRON_SCHEDULE=@hourly`. Quote an expression that contains
   spaces. Without the variable, no cron runs.
3. Add a custom background process to the App cluster:

   ```bash
   cloud background-process:create <instance> --type=custom --command='vendor/bin/dr lc:cron'
   ```

4. If the environment scales to zero, enable "Wake up interval" in the App
   cluster settings in the dashboard. Cron runs within a minute of waking.

Cloud runs a background process on every replica, so cron runs once per
replica. For an App cluster with several replicas, put the background process
on a Worker cluster with a single replica instead (untested).

### Database backup

`dr lc:db-backup` uploads the database as `db.sql.gz`, replacing the previous
one. Cache, session and log tables are empty. The backup is not sanitized;
share the read-only URL accordingly.

1. Create a private bucket with two keys. Do not attach it to an environment.

   ```bash
   cloud bucket:create --name=<name> --visibility=private --allowed-origins=''
   ```

   ```bash
   cloud bucket-key:create <bucket> --name=production --permission=read_write
   ```

   ```bash
   cloud bucket-key:create <bucket> --name=preview-read --permission=read_only
   ```

2. Build a URL for each key:
   `https://ACCESS_KEY_ID:SECRET_ACCESS_KEY@ENDPOINT_HOST/BUCKET_ID`
3. On the source environment, set the read-write URL as the custom
   environment variable `DB_BACKUP_URL` and change the deploy command:

   ```bash
   vendor/bin/drush deploy && (vendor/bin/dr lc:db-backup || true)
   ```

To fetch the backup elsewhere, such as CI, set `DB_BACKUP_URL` to the
read-only URL and run:

```bash
php -r 'require "vendor/autoload.php"; [$client, $object] = laravelcloud_db_backup(); $client->getObject($object + ["SaveAs" => "db.sql.gz"]);'
```

### Preview environments

A preview gets an empty database and only the custom variables defined in the
preview automation. In the automation:

- Set the initial deploy command to:

  ```bash
  php web/modules/contrib/laravelcloud/preview-deploy.php
  ```

- Define `APP_SECRET`.
- Define `DB_BACKUP_URL` with the read-only URL to import the
  [database backup](#database-backup). Without it, the script runs
  `drush site:install --existing-config`.
- Define `DRUPAL_CRON_SCHEDULE` only if cron should run on previews. It can
  send mail and call external services.

Changes to an automation apply to new previews only. A preview's background
process commands must exist on its branch; a background process that keeps
exiting takes the App cluster down.

A preview's bucket starts empty. To copy files from the source environment on
first request:

1. Add and enable these modules on all environments:

   ```bash
   composer require drupal/stage_file_proxy:^3 drupal/s3fs_file_proxy_to_s3:4.0.x-dev
   ```

2. In `stage_file_proxy.settings`, set `origin_dir` to `s3fs-public` and
   `origin` to the source environment's `AWS_URL`, or
   `https://<AWS_BUCKET>.laravel.cloud` with its bucket.

## Additional Requirements

- [Drush](https://www.drush.org)
- A Laravel Cloud application and the
  [`cloud` CLI](https://cloud.laravel.com/docs)

## Recommended modules/libraries

- [S3 File System](https://www.drupal.org/project/s3fs): public files in a
  Cloud bucket.
- [Stage File Proxy](https://www.drupal.org/project/stage_file_proxy) and
  [S3FS File Proxy to S3](https://www.drupal.org/project/s3fs_file_proxy_to_s3):
  files on preview environments.
- laravel_cloud_queue: Drupal queues on Cloud's managed queues.
