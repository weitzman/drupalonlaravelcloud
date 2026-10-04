# Laravel Cloud Queue

Integrates Drupal queues with
[Laravel Cloud managed queues](https://laravel.com/cloud/docs/queues). Cloud's
managed workers process the items, scale with the backlog and wake from zero
when an item arrives.

## Features

- A queue backend, `queue.laravelcloud`, for all queues or for selected
  ones. Queue worker plugins need no changes.
- A worker command, `dr lc:queue-work`, that Cloud's managed
  workers run.
- Failed items are retried with a doubling delay, then listed under failed
  jobs in the Cloud dashboard.
- `RequeueException`, `DelayedRequeueException` and `SuspendQueueException`
  return the item to the queue without counting as a failure.
- Jobs are named by their Drupal queue in the Cloud dashboard.
- Drupal queues can be mapped to separate managed queues, each with its own
  workers.

## Post-Installation

1. Copy the module's `console` file to `bin/console` in the project root.
   Cloud starts workers with
   `php bin/console messenger:consume cloud --queues=NAME --quiet`; the file
   passes that call to the module's command.

2. With the module enabled, select the backend in `settings.php`:

   ```php
   if (getenv('LARAVEL_CLOUD_MANAGED_QUEUES_CONFIG')) {
     $settings['queue_default'] = 'queue.laravelcloud';
     // Or: $settings['queue_service_MY_QUEUE'] = 'queue.laravelcloud';
   }
   ```

   Elsewhere, such as in local development, queues use Drupal's default
   backend.

Only the managed workers process these queues. Cron and `drush queue:run`
find nothing to claim.

### Options

To override the defaults, set these parameters in a site services YAML file:

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

## Additional Requirements

- Cloud must detect the project as a Symfony app. It does when the site's
  `composer.json` requires `symfony/framework-bundle` at the time the
  **Cloud application is created**.
- A managed queue in the Cloud environment.

## Recommended modules/libraries

- [Laravel Cloud](https://www.drupal.org/project/laravelcloud): settings,
  cron, database backups and preview environments for Drupal on Cloud.
