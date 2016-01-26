<?php
/**
 * Set default timezone
 */
date_default_timezone_set('Asia/Jakarta');

/**
 * Load all composer dependencies
 */
require ROOT_DIR.'vendor/autoload.php';

/**
 * Settings file
 */
$settingsFile = APP_DIR.'settings.php';
file_exists($settingsFile) || die ('Setting file not available');

session_start();

/**
 * Create new Slim\App instance
 *
 * @var Slim\App $app
 */
$app = new Slim\App([
    'settings' => require $settingsFile
]);

require APP_DIR.'dependencies.php';
require APP_DIR.'middlewares.php';
require APP_DIR.'routes.php';

return $app;
