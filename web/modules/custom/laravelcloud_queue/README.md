# Laravel Cloud Queue

Runs Drupal queues on
[Laravel Cloud managed queues](https://laravel.com/cloud/docs/queues). Cloud's
managed workers process the items, scale with the backlog and wake from zero
when an item arrives.

## Features

- A queue backend, `queue.laravel_cloud`, for all queues or for selected
  ones. Queue worker plugins need no changes.
- A worker command, `dr laravel-cloud-queue:work`, that Cloud's managed
  workers run.
- Failed items are retried with a doubling delay, then listed under failed
  jobs in the Cloud dashboard.
- `RequeueException`, `DelayedRequeueException` and `SuspendQueueException`
  return the item to the queue without counting as a failure.
- Jobs are named by their Drupal queue in the Cloud dashboard.
- Drupal queues can be mapped to separate managed queues, each with its own
  workers.

## Post-Installation

1. Add `bin/console` to the project root. Cloud starts workers with
   `php bin/console messenger:consume cloud --queues=NAME --quiet`; this
   passes that call to the module's command:

   ```php
   #!/usr/bin/env php
   <?php
   $root = dirname(__DIR__);
   $args = array_slice($argv, 1);
   if (($args[0] ?? '') === 'messenger:consume') {
     $options = array_filter(array_slice($args, 1), fn (string $arg) => str_starts_with($arg, '-'));
     $args = ['laravel-cloud-queue:work', ...$options];
   }
   chdir($root);
   pcntl_exec(PHP_BINARY, [$root . '/vendor/bin/dr', ...$args]);
   ```

2. With the module enabled, select the backend in `settings.php`:

   ```php
   if (getenv('LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG')) {
     $settings['queue_default'] = 'queue.laravel_cloud';
     // Or: $settings['queue_service_MY_QUEUE'] = 'queue.laravel_cloud';
   }
   ```

   Elsewhere, such as in local development, queues use Drupal's default
   backend.

Only the managed workers process these queues. Cron and `drush queue:run`
find nothing to claim.

### Options

Set these parameters in a site services YAML file:

| Parameter | Default | Purpose |
| --- | --- | --- |
| `laravelcloud_queue.max_tries` | 4 | Attempts before an item is a failed job |
| `laravelcloud_queue.backoff` | 1 | Seconds before the first retry; doubles with each attempt |
| `laravelcloud_queue.queue_map` | `{}` | Managed queue names keyed by Drupal queue name |

Unmapped Drupal queues use the environment's default managed queue. A mapped
managed queue must already exist in the Cloud environment:

```yaml
parameters:
  laravelcloud_queue.queue_map:
    media_entity_thumbnail: media
```

### Limits

- `createItem()` throws an exception for an item larger than 1 MiB when
  encoded.
- `numberOfItems()` is the approximate number of waiting messages in the
  managed queue, including items of other Drupal queues that share it.

## Additional Requirements

- Cloud must detect the project as a Symfony app. It does when the site's
  `composer.json` requires `symfony/framework-bundle` at the time the Cloud
  application is created.
- A managed queue in the Cloud environment.

## Recommended modules/libraries

- [Laravel Cloud](https://www.drupal.org/project/laravelcloud): settings,
  cron, database backups and preview environments for Drupal on Cloud.
