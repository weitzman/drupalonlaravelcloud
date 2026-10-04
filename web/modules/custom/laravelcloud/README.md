# Laravel Cloud

Runs Drupal cron on [Laravel Cloud](https://cloud.laravel.com), and documents
how to host a Drupal site there. The setup below works with an environment
that scales to zero.

The module provides the commands `dr lc:cron` and `dr lc:db-backup`, and a
deploy script for preview environments. Queues are handled by the separate [laravel_cloud_queue](../laravel_cloud_queue/README.md) module.

## Project layout

- **Web root:** Cloud serves `public/`. Keep Drupal's docroot at `web/` and
  commit a symlink: `ln -s web public`.
- **`composer.lock`:** commit it. Cloud reads it to see the installed packages.
- **Framework detection:** Cloud treats the project as a Symfony app when the
  root `composer.json` requires `symfony/framework-bundle` at the time the
  Cloud application is created. Managed queues depend on this; see
  laravel_cloud_queue.
- **`.env`:** Cloud writes the variables for attached resources and your
  custom variables to `.env` in the project root; they are not in the process
  environment. Load the file from a script listed under `autoload.files` in
  the root `composer.json`, before this module's `laravelcloud.php`:

  ```php
  if (file_exists(__DIR__ . '/.env')) {
    (new \Symfony\Component\Dotenv\Dotenv())->usePutenv()->load(__DIR__ . '/.env');
  }
  ```

  A value that Dotenv cannot parse, such as one with a stray quote, stops
  every PHP command.
- **Build command:**
  `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader`
- **Deploy command:** `vendor/bin/drush deploy`

## Settings

Cloud injects the attached resources as environment variables. Two steps make
Drupal read them; neither has any effect off Cloud.

1. Have Composer load the module's `laravelcloud.php`. A module installed with
   Composer does this itself. For a copy committed to the project, add the
   file to the root `composer.json` and run `composer dump-autoload`:

   ```json
   "autoload": {
       "files": ["web/modules/custom/laravelcloud/laravelcloud.php"]
   }
   ```

2. Call its function at the end of `settings.php`:

   ```php
   laravelcloud_settings($settings, $databases, $config);
   ```

Together these set:

- **Database:** from `DATABASE_URL` (MySQL).
- **Hash salt:** from `APP_SECRET`. Cloud does not define it for the
  environment; add it as a custom environment variable. Without it, the salt
  is derived from `DATABASE_URL`.
- **Site URL:** Cloud provides the URL as `DEFAULT_URI` (Symfony apps) or
  `APP_URL`. It is copied to `DRUSH_OPTIONS_URI` and `DRUPAL_URL` so CLI
  commands, including cron, generate correct links.
- **Files:** the s3fs settings described below, when a bucket is attached.

### Files

Instances have no persistent disk, so public files go to an object storage
bucket (Cloudflare R2) through [s3fs](https://www.drupal.org/project/s3fs):

1. `composer require drupal/s3fs` and enable the module.
2. In the Cloud dashboard, create a bucket with public visibility and attach
   it to the environment. Cloud then writes the bucket name, endpoint and
   credentials to `.env` as `AWS_*` variables.

`laravelcloud_settings()` then makes s3fs take over `public://`, serve files from the
bucket's public host (`https://<AWS_BUCKET>.laravel.cloud`), and work within
R2's limits: no per-object ACLs and no object version listing. To serve files
from another base URL, such as a custom domain, set `AWS_URL` as a custom
environment variable.

## Cron

Uninstall `automated_cron`, then:

1. Enable this module and deploy.
2. Add a custom background process to the App cluster, in the dashboard or
   with:

   ```bash
   cloud background-process:create <instance> --type=custom --command='vendor/bin/dr lc:cron'
   ```

3. If the environment scales to zero, enable "Wake up interval" in the App
   cluster settings in the dashboard, for example 60 minutes. The CLI does not
   expose this setting.

The command checks every minute and runs `dr system:cron` when a time in the
schedule has passed since the last run. The schedule is hourly; set the
`DRUPAL_CRON_SCHEDULE` environment variable to another cron expression (UTC)
to change it. On start, the last run is read from Drupal's `system.cron_last`.
Set it to `off` to run no cron; the process then idles, because a background
process that exits is restarted by Cloud.

The background process does not keep the environment awake. It sleeps after
the "Sleep after" timeout without HTTP requests, the wake up interval wakes it
(observed on the clock hour), and cron runs within a minute of waking. Cron
output appears in the environment logs.

### Several replicas

Cloud runs a background process on every replica of its cluster. On an App
cluster with several replicas, cron therefore runs once per replica at each
scheduled time. Drupal's cron lock prevents overlapping runs, but not runs
that follow each other.

For an App cluster that autoscales, put the background process on a Worker
cluster with a single replica instead. Cloud's documentation recommends an
always-awake worker cluster for Symfony scheduled tasks; no wake up interval
is needed then. Autoscaling and worker clusters depend on the Cloud plan. This
arrangement has not been tested with this module.

### Why not Cloud's Scheduler

The Scheduler toggle is not usable for a Symfony-detected app that scales to
zero. The dashboard disables it ("Symfony applications do not support
scheduled tasks while sleeping"), and a deploy resets it when it is set
through the CLI. When on, Cloud calls
`php artisan schedule:list --json --timezone=UTC` at deploy and
`php artisan schedule:run` at the listed times; it never calls `bin/console`
for scheduling, and stderr from those calls is not in the environment logs.

## Database backup

`dr lc:db-backup` dumps the database, gzipped and with cache, session and log
tables empty, and uploads it to a private bucket as `db.sql.gz`, replacing the
previous one. Preview environments and CI use it as their database, so they
need neither the source database's credentials nor network access to it.

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

2. Combine each key with the bucket's endpoint host and ID into a URL:
   `https://ACCESS_KEY_ID:SECRET_ACCESS_KEY@ENDPOINT_HOST/BUCKET_ID`.
3. On the environment to copy, add the read-write URL as the custom
   environment variable `DB_BACKUP_URL`, and upload after each deploy. With
   this deploy command, a failed upload does not fail the deploy:

   ```bash
   vendor/bin/drush deploy && (vendor/bin/dr lc:db-backup || true)
   ```

The backup is not sanitized: it contains user accounts and any other private
data in the database. Give the read-only URL only to environments and people
who may see that.

To fetch the backup elsewhere, such as in CI, set `DB_BACKUP_URL` to the
read-only URL and, after `composer install`:

```bash
php -r 'require "vendor/autoload.php"; [$client, $object] = laravelcloud_db_backup(); $client->getObject($object + ["SaveAs" => "db.sql.gz"]);'
```

## Preview environments

A preview environment copies the clusters and background processes of the
environment it is based on, but gets a new, empty database, and only the
variables defined in the preview automation. `drush deploy` fails on an empty
database, so set the automation's initial deploy command to:

```bash
php web/modules/custom/laravelcloud/preview-deploy.php
```

When the database has no tables, the script fills it, then runs
`drush deploy`:

- With `DB_BACKUP_URL` set in the automation's variables, it imports the
  [database backup](#database-backup). Use the read-only URL.
- Without it, it runs `drush site:install --existing-config`. That does not
  work for an install profile that implements `hook_install()`, such as
  `demo_umami`.

Later deploys of the preview use the source environment's deploy command. If
that includes `dr lc:db-backup`, the upload is refused there because the
preview holds the read-only URL, and the source's backup is left alone.

Changes to an automation apply to new previews only; edit an existing
preview's deploy command and variables in its own settings.

Also define `APP_SECRET` in the automation. A preview that gets its own bucket
uses it without further settings, but the bucket starts empty: files uploaded
on the source environment are missing.

A preview does not copy the App cluster's wake up interval; it is off, so a
sleeping preview stays asleep until it gets a request, and cron only runs
while it is awake.

Set `DRUPAL_CRON_SCHEDULE=off` in the automation unless previews need cron.
Cron on a preview works on a copy of the source's data and can send mail or
call external services.

A background process that keeps exiting takes the whole App cluster down with
it, so a preview's background process commands must exist on its branch.

## Checking on an environment

```bash
cloud environment:logs
```

```bash
cloud command:run production --cmd='vendor/bin/drush watchdog:show --type=cron'
```

```bash
cloud instance:list --json
```

For a failed deploy, the output of the deploy command is in `failureReason`:

```bash
cloud deployment:get <deployment> --json
```

Other build and deploy logs are only in the dashboard.

## Local development

Pull an environment's database into DDEV with this project's provider,
`.ddev/providers/laravel-cloud.yaml`:

```bash
ddev pull laravel-cloud
```

It needs the `cloud` CLI, authenticated, on the host. Files are not pulled;
use [stage_file_proxy](https://www.drupal.org/project/stage_file_proxy).
