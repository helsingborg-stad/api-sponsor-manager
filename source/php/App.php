<?php

declare(strict_types=1);

namespace ApiSponsorManager;

use AcfService\AcfService;
use ApiSponsorManager\Helper\CronScheduler\CronEvent;
use ApiSponsorManager\Helper\CronScheduler\CronSchedulerInterface;
use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use ApiSponsorManager\Helper\NotificationServices\NotificationService;
use ApiSponsorManager\Notifications;
use WpService\WpService;

class App
{
    public function __construct(
        private WpService $wpService,
        private AcfService $acfService,
        private NotificationService $notificationService,
        private CronSchedulerInterface $cronScheduler
    ) {
        $this->init(...[
            new Assignment\PostType($wpService),
            new Offering\PostType($wpService),
            new Activity\Taxonomy($wpService),
            new Resource\Taxonomy($wpService),
            new OptionsPage($wpService, $acfService),
            new Notifications($wpService, $acfService, $notificationService),
            $cronScheduler
        ]);

        $cronScheduler->addEvent(
            new CronEvent(
                'daily', 
                'sponsor_manager_delete_expired_posts_cron', 
                [new DeleteExpiredPost($wpService, $acfService), 'onCronEvent']
            )
        );
    }

    public function init(Hookable ...$hookables)
    {
        foreach ($hookables as $hookable) {
            $hookable->addHooks();
        }
    }
}
