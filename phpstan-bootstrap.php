<?php

declare(strict_types=1);

/**
 * Bootstrap PHPStan — production-like environment.
 *
 * Uses ENVIRONMENT='development' (not 'testing') so that Config comparisons
 * such as ENVIRONMENT === 'production' are not always-true/false for PHPStan.
 * Based on the CI4 Test bootstrap minus the testing-specific overrides.
 */

$_SERVER['CI_ENVIRONMENT'] = 'development';
defined('ENVIRONMENT') || define('ENVIRONMENT', 'development');

defined('CI_DEBUG') || define('CI_DEBUG', false);

defined('HOMEPATH') || define('HOMEPATH', realpath(rtrim(__DIR__, '\\/ ')) . DIRECTORY_SEPARATOR);
$source = is_dir(HOMEPATH . 'app')
    ? HOMEPATH
    : (is_dir('vendor/codeigniter4/framework/') ? 'vendor/codeigniter4/framework/' : 'vendor/codeigniter4/codeigniter4/');
defined('CONFIGPATH') || define('CONFIGPATH', realpath($source . 'app/Config') . DIRECTORY_SEPARATOR);
defined('PUBLICPATH') || define('PUBLICPATH', realpath($source . 'public') . DIRECTORY_SEPARATOR);
unset($source);

require CONFIGPATH . 'Paths.php';
$paths = new Config\Paths();

defined('APPPATH')    || define('APPPATH', realpath(rtrim($paths->appDirectory, '\\/ ')) . DIRECTORY_SEPARATOR);
defined('ROOTPATH')   || define('ROOTPATH', realpath(APPPATH . '../') . DIRECTORY_SEPARATOR);
defined('SYSTEMPATH') || define('SYSTEMPATH', realpath(rtrim($paths->systemDirectory, '\\/')) . DIRECTORY_SEPARATOR);
defined('WRITEPATH')  || define('WRITEPATH', realpath(rtrim($paths->writableDirectory, '\\/ ')) . DIRECTORY_SEPARATOR);
defined('TESTPATH')   || define('TESTPATH', realpath(HOMEPATH . 'tests/') . DIRECTORY_SEPARATOR);

defined('CIPATH')    || define('CIPATH', realpath(SYSTEMPATH . '../') . DIRECTORY_SEPARATOR);
defined('FCPATH')    || define('FCPATH', realpath(PUBLICPATH) . DIRECTORY_SEPARATOR);

defined('COMPOSER_PATH') || define('COMPOSER_PATH', (string) realpath(HOMEPATH . 'vendor/autoload.php'));
defined('VENDORPATH')    || define('VENDORPATH', realpath(HOMEPATH . 'vendor') . DIRECTORY_SEPARATOR);

// Load CI4 autoloader without the test-layer bootstrap (which pollutes ENVIRONMENT).
// PHPStan only needs class definitions; Composer PSR-4 mappings cover App\ and CI4 system classes.
require COMPOSER_PATH;
