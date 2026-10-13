<?php
/**
 * Bootstrap for PHPUnit
 */

use Composer\InstalledVersions;

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}
if (!defined('VENDORS')) {
    define('VENDORS', dirname(__DIR__) . DS . 'vendor' . DS);
}
if (!defined('ROOT')) {
    define('ROOT', realpath(InstalledVersions::getInstallPath('pieceofcake2/app')));
}

require_once InstalledVersions::getInstallPath('pieceofcake2/cakephp') . DS . 'tests' . DS . 'bootstrap.php';

CakePlugin::load('Aws', [
    'path' => dirname(__DIR__) . DS,
]);
