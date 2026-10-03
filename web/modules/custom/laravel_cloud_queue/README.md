# Laravel Cloud Queue

Runs Drupal queues on
[Laravel Cloud managed queues](https://laravel.com/cloud/docs/queues). Items
are sent to the managed queue when created and processed by Cloud's managed
workers, which scale with the backlog and wake from zero when an item arrives.

Queue worker plugins need no changes. This module has no dependency on other
Drupal modules; it uses the configuration parser and agent client from
[laravel/symfony-on-cloud](https://github.com/laravel/symfony-on-cloud).

## Requirements

- Laravel Cloud must detect the project as a Symfony app. It does so when the
  root `composer.json` requires `symfony/framework-bundle` at the time the
  Cloud application is created.
- A managed queue in the Cloud environment.

## Setup

1. Enable this module.

2. Add `bin/console` to the project root. Laravel Cloud starts workers with
   `php bin/console messenger:consume cloud --queues=NAME --quiet`; this hands
   that call to the module's worker command:

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

3. Use the backend for all queues, or for one queue, in `settings.php`. Add
   this after the module is enabled, and only where a managed queue exists:

   ```php
   if (getenv('LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG')) {
     $settings['queue_default'] = 'queue.laravel_cloud';
     // Or: $settings['queue_service_MY_QUEUE'] = 'queue.laravel_cloud';
   }
   ```

## Behavior

- **Processing:** only the managed workers process these queues. Cron and
  `drush queue:run` find nothing to claim.
- **Retries:** an item whose worker throws is retried with a doubling delay,
  then removed and listed under failed jobs in the Cloud dashboard. Set
  `laravel_cloud_queue.max_tries` (default 4) and `laravel_cloud_queue.backoff`
  (default 1 second) in a site services YAML file.
- **Requeue exceptions:** `RequeueException`, `DelayedRequeueException` and
  `SuspendQueueException` return the item to the queue, with the requested
  delay, without counting as a failure.
- **Dashboard:** jobs are named by their Drupal queue.
- **Size:** an item larger than 1 MiB when encoded is rejected by
  `createItem()` with an exception.
- **Counts:** `numberOfItems()` is the approximate number of waiting messages
  in the managed queue, including items of other Drupal queues that share it.

## Several managed queues

Items go to the environment's default managed queue unless their Drupal queue
is mapped to another one. Each managed queue has its own workers, so map a
queue to give it separate memory or scaling:

```yaml
parameters:
  laravel_cloud_queue.queue_map:
    media_entity_thumbnail: media
```

The managed queue must already exist in the Cloud environment.

## Local development

Without the settings above, queues use Drupal's default backend. To run
against SQS directly, set `LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG` and run
`vendor/bin/dr laravel-cloud-queue:work --queues=NAME`.
