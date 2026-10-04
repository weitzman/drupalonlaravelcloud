# Laravel Cloud

Runs Drupal cron on [Laravel Cloud](https://cloud.laravel.com), and documents
how to host a Drupal site there. The setup below works with an environment
that scales to zero.

The module provides one command, `dr laravelcloud:cron`. Queues are handled by
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

Cloud sets `LARAVEL_CLOUD` and injects the attached resources as environment
variables. In `settings.php`:

```php
if (getenv('LARAVEL_CLOUD')) {
  // Prefer an explicit secret; fall back to a value derived from the DB URL.
  $settings['hash_salt'] = getenv('APP_SECRET') ?: hash('sha256', getenv('DATABASE_URL'));
  $db = parse_url(getenv('DATABASE_URL'));
  $databases['default']['default'] = [
    'database' => ltrim($db['path'], '/'),
    'username' => $db['user'],
    'password' => $db['pass'],
    'host' => $db['host'],
    'port' => $db['port'],
    'driver' => 'mysql',
    'prefix' => '',
    'collation' => 'utf8mb4_general_ci',
  ];
}
```

- **Site URL:** Cloud provides the URL as `DEFAULT_URI` (Symfony apps) or
  `APP_URL`. Copy it to `DRUSH_OPTIONS_URI` and `DRUPAL_URL` so CLI commands
  generate correct links. This project does that in `load.environment.php`,
  autoloaded through `autoload.files` in `composer.json`.
- **Files:** instances have no persistent disk. Attach an object storage
  bucket and use [s3fs](https://www.drupal.org/project/s3fs) for public files.
  Cloud injects the `AWS_*` variables except `AWS_URL`, the bucket's public
  base URL; add that as a custom environment variable. The bucket is
  Cloudflare R2, which needs `s3fs.upload_as_private` and
  `disable_version_sync`. See the `s3fs` lines in this project's
  `settings.php`.

## Cron

Uninstall `automated_cron`, then:

1. Enable this module and deploy.
2. Add a custom background process to the App cluster, in the dashboard or
   with:

   ```bash
   cloud background-process:create <instance> --type=custom --command='php vendor/bin/dr laravelcloud:cron'
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
cloud command:run production --cmd='vendor/bin/drush ws --type=cron'
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
