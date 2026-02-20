<?php
namespace ApiSponsorManager;

use ApiSponsorManager\App;

use Brain\Monkey\Functions;
use Mockery;

class AppTest extends \PluginTestCase\PluginTestCase
{
    public function testAddHooks()
    {
        new App();
    
        self::assertNotFalse(has_action('admin_enqueue_scripts', 'ApiSponsorManager\App->enqueueStyles()'));
        self::assertNotFalse(has_action('admin_enqueue_scripts', 'ApiSponsorManager\App->enqueueScripts()'));
    }

    public function testEnqueueStyles()
    {
        Functions\expect('wp_register_style')->once();
        Functions\expect('wp_enqueue_style')->once()->with('api-sponsor-manager-css');

        $app = new App();

        $app->enqueueStyles();
    }

    public function testEnqueueScripts()
    {
        Functions\expect('wp_register_script')->once();
        Functions\expect('wp_enqueue_script')->once()->with('api-sponsor-manager-js');

        $app = new App();

        $app->enqueueScripts();
    }
}
