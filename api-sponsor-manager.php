<?php

/**
 * Plugin Name:       API Sponsor Manager
 * Plugin URI:        https://github.com/helsingborg-stad/api-sponsor-manager
 * Description:       Manages looking for sponsor listnings.
 * Version:           1.0.0
 * Author:            Nikolas Ramsted
 * Author URI:        https://github.com/helsingborg-stad
 * License:           MIT
 * License URI:       https://opensource.org/licenses/MIT
 * Text Domain:       api-sponsor-manager
 * Domain Path:       /languages
 */

use AcfService\Implementations\NativeAcfService;
use ApiSponsorManager\Helper\NotificationServices\FakeNotificationService;
use ApiSponsorManager\Helper\NotificationServices\WordPressNotificationService;
use WpService\Implementations\NativeWpService;
use WpUtilService\WpUtilService;

// Protect agains direct file access
if (!defined('WPINC')) {
    die();
}

define('API_SPONSOR_MANAGER_PATH', plugin_dir_path(__FILE__));
define('API_SPONSOR_MANAGER_URL', plugins_url('', __FILE__));
define('API_SPONSOR_MANAGER_TEMPLATE_PATH', API_SPONSOR_MANAGER_PATH . 'templates/');
define('API_SPONSOR_MANAGER_TEXT_DOMAIN', 'api-sponsor-manager');

load_plugin_textdomain(API_SPONSOR_MANAGER_TEXT_DOMAIN, false, API_SPONSOR_MANAGER_PATH . '/languages');

require_once API_SPONSOR_MANAGER_PATH . 'Public.php';

// Register the autoloader
require __DIR__ . '/vendor/autoload.php';

// Acf auto import and export
add_action('acf/init', function () {
    $acfExportManager = new \AcfExportManager\AcfExportManager();
    $acfExportManager->setTextdomain('api-sponsor-manager');
    $acfExportManager->setExportFolder(API_SPONSOR_MANAGER_PATH . 'source/php/AcfFields/');
    $acfExportManager->autoExport(array(
        'api-sponsor-manager-assignment' => 'group_69a97690d547c',
        'api-sponsor-manager-organization' => 'group_69a9552f0e029',
        'api-sponsor-manager-notifications' => 'group_69bbaf273446a',
    ));
    $acfExportManager->import();
});

$wpService = new NativeWpService();
$wpUtilService = new WpUtilService($wpService);

// Start application
new ApiSponsorManager\App(
    $wpService, 
    new NativeAcfService(), 
    defined('SPONSOR_MANAGER_EMAIL_SERVICE') 
        && SPONSOR_MANAGER_EMAIL_SERVICE === 'fake' 
        ? new FakeNotificationService() 
        : new WordPressNotificationService($wpService)
);
