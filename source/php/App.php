<?php

declare(strict_types=1);

namespace ApiSponsorManager;

use AcfService\AcfService;
use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use ApiSponsorManager\Helper\NotificationServices\NotificationService;
use ApiSponsorManager\Notifications;
use WpService\WpService;

class App
{
    public function __construct(
        private WpService $wpService,
        private AcfService $acfService,
        private NotificationService $notificationService
    ) {
        $this->init(...[
            new Assignment\PostType($wpService),
            new Offering\PostType($wpService),
            new Activity\Taxonomy($wpService),
            new Resource\Taxonomy($wpService),
            new OptionsPage($wpService, $acfService),
            new Notifications($wpService, $acfService, $notificationService)
        ]);
    }

    public function init(Hookable ...$hookables)
    {
        foreach ($hookables as $hookable) {
            $hookable->addHooks();
        }
    }
}
