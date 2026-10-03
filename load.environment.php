<?php

/**
 * @file
 * Loads the project's .env file. Autoloaded via composer.json "autoload.files".
 */

use Symfony\Component\Dotenv\Dotenv;

// Existing environment variables take precedence over values in .env.
if (file_exists(__DIR__ . '/.env')) {
  (new Dotenv())->usePutenv()->load(__DIR__ . '/.env');
}

// Laravel Cloud provides the site URL as DEFAULT_URI (Symfony apps) or APP_URL.
if ($url = getenv('DEFAULT_URI') ?: getenv('APP_URL')) {
  foreach (['DRUSH_OPTIONS_URI', 'DRUPAL_URL'] as $name) {
    if (getenv($name) === FALSE) {
      putenv("$name=$url");
      $_ENV[$name] = $_SERVER[$name] = $url;
    }
  }
}
