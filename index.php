<?php

/**
 * @file
 * The PHP page that serves all page requests on a Drupal installation.
 *
 * All Drupal code is released under the GNU General Public License.
 * See COPYRIGHT.txt and LICENSE.txt files in the "core" directory.
 */

chdir('public');
$_SERVER['SCRIPT_FILENAME'] = '/var/www/html/public/index.php';
require 'index.php';
