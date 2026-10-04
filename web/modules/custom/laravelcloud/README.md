# Laravel Cloud

Runs Drupal cron on [Laravel Cloud](https://cloud.laravel.com), and documents
how to host a Drupal site there. The setup below works with an environment
that scales to zero.

The module provides one command, `dr lc:cron`. Queues are handled by
the separate [laravel_cloud_queue](../laravel_cloud_queue/README.md) module.

## Project layout

- **Web root:** Cloud serves `public/`. Keep Drupal's docroot at `web/` and
  commit a symlink: `ln -s web public`.
- **`composer.lock`:** commit it. Cloud reads it to see the installed packages.
- **Framework detection:** Cloud treats the project as a Symfony app when the
  root `composer.json` requires `symfony/framework-bundle` at the time the
  Cloud application is created. Managed queues depend on this; see
  laravel_cloud_queue.
- **Build command:**
  `composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader`
- **Deploy command:** `vendor/bin/drush deploy`

## Settings

Cloud injects the attached resources as environment variables. Include this
module's settings file from `settings.php`; it does nothing off Cloud:

```php
include $app_root . '/modules/custom/laravelcloud/settings.laravelcloud.php';
```

It sets:

- **Database:** from `DATABASE_URL` (MySQL).
- **Hash salt:** from `APP_SECRET` if you add that environment variable,
  otherwise derived from `DATABASE_URL`.
- **Files:** the s3fs settings described below, when a bucket is attached.

### Site URL

The module cannot do this step, because the variables must exist before Drush
starts. Cloud provides the URL as `DEFAULT_URI` (Symfony apps) or `APP_URL`.
Copy it to `DRUSH_OPTIONS_URI` and `DRUPAL_URL` so CLI commands, including
cron, generate correct links. Put this in a file listed under
`autoload.files` in the root `composer.json`; this project uses
`load.environment.php`:

```php
if ($url = getenv('DEFAULT_URI') ?: getenv('APP_URL')) {
  foreach (['DRUSH_OPTIONS_URI', 'DRUPAL_URL'] as $name) {
    if (getenv($name) === FALSE) {
      putenv("$name=$url");
      $_ENV[$name] = $_SERVER[$name] = $url;
    }
  }
}
```

### Files

Instances have no persistent disk, so public files go to an object storage
bucket (Cloudflare R2) through [s3fs](https://www.drupal.org/project/s3fs):

1. `composer require drupal/s3fs` and enable the module.
2. In the Cloud dashboard, create a bucket with public visibility and attach
   it to the environment. Cloud then injects the bucket name, endpoint and
   credentials as `AWS_*` variables.
3. Add the bucket's public base URL as a custom environment variable named
   `AWS_URL`. Cloud does not inject it, and the settings file ignores the
   bucket without it.

The settings file then makes s3fs take over `public://`, serve files from the
`AWS_URL` host, and work within R2's limits: no per-object ACLs and no object
version listing.

## Cron

Uninstall `automated_cron`, then:

1. Enable this module and deploy.
2. Add a custom background process to the App cluster, in the dashboard or
   with:

   ```bash
   cloud background-process:create <instance> --type=custom --command='php vendor/bin/dr lc:cron'
   ```

3. If the environment scales to zero, enable "Wake up interval" in the App
   cluster settings in the dashboard, for example 60 minutes. The CLI does not
   expose this setting.

The command checks every minute and runs `dr system:cron` when a time in the
schedule has passed since the last run. The schedule is hourly; set the
`DRUPAL_CRON_SCHEDULE` environment variable to another cron expression (UTC)
to change it. On start, the last run is read from Drupal's `system.cron_last`.

The background process does not keep the environment awake. It sleeps after
the "Sleep after" timeout without HTTP requests, the wake up interval wakes it
(observed on the clock hour), and cron runs within a minute of waking. Cron
output appears in the environment logs.

### Why not Cloud's Scheduler

The Scheduler toggle is not usable for a Symfony-detected app that scales to
zero. The dashboard disables it ("Symfony applications do not support
scheduled tasks while sleeping"), and a deploy resets it when it is set
through the CLI. When on, Cloud calls
`php artisan schedule:list --json --timezone=UTC` at deploy and
`php artisan schedule:run` at the listed times; it never calls `bin/console`
for scheduling, and stderr from those calls is not in the environment logs.

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

The CLI does not show build or deploy logs; `deployment:get` and
`deploy:monitor` return only a status. Read those logs in the dashboard.

## Local development

Pull an environment's database into DDEV with this project's provider,
`.ddev/providers/laravel-cloud.yaml`:

```bash
ddev pull laravel-cloud
```

It needs the `cloud` CLI, authenticated, on the host. Files are not pulled;
use [stage_file_proxy](https://www.drupal.org/project/stage_file_proxy).
