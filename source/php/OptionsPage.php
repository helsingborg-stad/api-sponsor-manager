<?php

namespace ApiSponsorManager;

use AcfService\Contracts\AddOptionsPage;
use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use WpService\Contracts\__;
use WpService\Contracts\AddAction;

class OptionsPage implements Hookable
{
    public function __construct(private AddAction&__ $wpService, private AddOptionsPage $acfService)
    {
    }

    public function addHooks(): void
    {
        $this->wpService->addAction('acf/init', [$this, 'registerSettingsPage']);
    }

    public function registerSettingsPage(): void
    {
        $this->acfService->addOptionsPage(array(
            'menu_slug'       => 'api-sponsor-manager-settings',
            'page_title'      => $this->wpService->__('API Sponsor Manager Settings', 'api-sponsor-manager'),
            'active'          => true,
            'menu_title'      => $this->wpService->__('Sponsor Manager', 'api-sponsor-manager'),
            'capability'      => 'administrator',
            'parent_slug'     => 'options-general.php',
            'position'        => '',
            'icon_url'        => '',
            'redirect'        => true,
            'post_id'         => 'options',
            'autoload'        => false,
            'update_button'   => 'Update',
            'updated_message' => 'Settings updated',
        ));
    }
}
