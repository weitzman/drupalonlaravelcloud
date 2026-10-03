# Laravel Cloud Managed Queues transport

Runs Drupal queues and Symfony Messenger messages on
[Laravel Cloud managed queues](https://laravel.com/cloud/docs/queues). It
registers the transport from
[laravel/symfony-on-cloud](https://github.com/laravel/symfony-on-cloud) with the
[Symfony Messenger](https://www.drupal.org/project/sm) module.

## Requirements

- Laravel Cloud must detect the project as a Symfony app. It does so when the
  root `composer.json` requires `symfony/framework-bundle` at the time the
  Cloud application is created.
- A managed queue in the Cloud environment.

## Setup

1. Enable this module. It defines a transport named `cloud`.

2. Add `bin/console` to the project root. Laravel Cloud starts workers with
   `php bin/console messenger:consume cloud --queues=NAME --quiet`, and Drupal
   provides that command through `dr`:

   ```php
   #!/usr/bin/env php
   <?php
   $root = dirname(__DIR__);
   chdir($root);
   pcntl_exec(PHP_BINARY, [$root . '/vendor/bin/dr', ...array_slice($argv, 1)]);
   ```

3. Route messages to the transport in a site services YAML file:

   ```yaml
   parameters:
     sm.routing:
       Drupal\sm\QueueInterceptor\SmLegacyDrupalQueueItem: cloud
   ```

4. To send Drupal queue items through Messenger, add to `settings.php` after
   the modules are enabled:

   ```php
   $settings['queue_default'] = 'Drupal\sm\QueueInterceptor\SmLegacyQueueFactory';
   ```

Queue workers then run on Cloud's managed workers. Cron and
`drush queue:run` no longer process these queues.

## Behavior

- Failed items are retried with the retry strategy of the `cloud` transport
  in `sm.transports` (3 retries by default), then reported as failed jobs in
  the Cloud dashboard.
- A message larger than 1 MiB when encoded is rejected at dispatch with an
  error naming the Drupal queue.
- All items go to the environment's default managed queue. Dispatch a message
  with `CloudQueueStamp` to target another queue.
- Outside Laravel Cloud the transport is defined but not configured, so do not
  route messages to it there.
