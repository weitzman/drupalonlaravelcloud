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
