<?php

/**
 * @file
 * Early setup for a site on Laravel Cloud. Loaded by Composer's autoloader.
 */

use Aws\S3\S3Client;

// Laravel Cloud provides the site URL as DEFAULT_URI (Symfony apps) or APP_URL.
// Drush and dr read these names, before Drupal starts.
if ($url = getenv('DEFAULT_URI') ?: getenv('APP_URL')) {
  foreach (['DRUSH_OPTIONS_URI', 'DRUPAL_URL'] as $name) {
    if (getenv($name) === FALSE) {
      putenv("$name=$url");
      $_ENV[$name] = $_SERVER[$name] = $url;
    }
  }
}

/**
 * Applies Laravel Cloud's database and file storage to Drupal's settings.
 *
 * Call from settings.php. Reads the environment variables Laravel Cloud
 * injects for the attached resources. Does nothing elsewhere.
 */
function laravelcloud_settings(array &$settings, array &$databases, array &$config): void {
  if (!getenv('LARAVEL_CLOUD')) {
    return;
  }

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

  // Store public files in the attached object storage bucket (Cloudflare R2),
  // using the s3fs module. A public bucket is served from a host named after
  // it; set AWS_URL to use another public base URL, such as a custom domain.
  if ($bucket = getenv('AWS_BUCKET')) {
    $settings['s3fs.access_key'] = getenv('AWS_ACCESS_KEY_ID');
    $settings['s3fs.secret_key'] = getenv('AWS_SECRET_ACCESS_KEY');
    $settings['s3fs.use_s3_for_public'] = TRUE;
    // R2 rejects per-object ACLs; visibility is set on the bucket.
    $settings['s3fs.upload_as_private'] = TRUE;
    $config['s3fs.settings']['bucket'] = $bucket;
    $config['s3fs.settings']['region'] = getenv('AWS_DEFAULT_REGION') ?: getenv('AWS_REGION') ?: 'auto';
    $config['s3fs.settings']['use_customhost'] = TRUE;
    $config['s3fs.settings']['hostname'] = getenv('AWS_ENDPOINT_URL') ?: getenv('AWS_ENDPOINT');
    // Virtual-hosted style keeps the bucket name out of public file URLs.
    $config['s3fs.settings']['use_path_style_endpoint'] = FALSE;
    // R2 does not implement ListObjectVersions.
    $config['s3fs.settings']['disable_version_sync'] = TRUE;
    $config['s3fs.settings']['use_https'] = TRUE;
    $config['s3fs.settings']['use_cname'] = TRUE;
    $config['s3fs.settings']['domain'] = parse_url(getenv('AWS_URL') ?: "https://$bucket.laravel.cloud", PHP_URL_HOST);
  }
}

/**
 * Returns the S3 client and object for the database backup at DB_BACKUP_URL.
 *
 * The URL has the form https://ACCESS_KEY:SECRET@ENDPOINT_HOST/BUCKET.
 *
 * @return array{\Aws\S3\S3Client, array{Bucket: string, Key: string}}
 *   The client, and the arguments that identify the backup object.
 */
function laravelcloud_db_backup(): array {
  $url = parse_url(getenv('DB_BACKUP_URL'));
  $client = new S3Client([
    'region' => 'auto',
    'endpoint' => 'https://' . $url['host'],
    'credentials' => ['key' => $url['user'], 'secret' => $url['pass']],
    'use_path_style_endpoint' => TRUE,
    // R2 does not accept the checksums the SDK adds by default.
    'request_checksum_calculation' => 'when_required',
    'response_checksum_validation' => 'when_required',
  ]);
  return [$client, ['Bucket' => trim($url['path'], '/'), 'Key' => 'db.sql.gz']];
}
