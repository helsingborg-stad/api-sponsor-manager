<?php

declare(strict_types=1);

namespace ApiSponsorManager;

use AcfService\AcfService;
use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use WpService\Contracts\AddAction;
use WpService\Contracts\AddFilter;
use WpService\Contracts\WpEnqueueScript;
use WpService\Contracts\WpEnqueueStyle;
use WpService\Contracts\WpRegisterScript;
use WpService\Contracts\WpRegisterStyle;
use WpUtilService\Features\Enqueue\EnqueueManager;

class App
{
    public function __construct(
        private EnqueueManager $wpEnqueue,
        private AddFilter&AddAction&WpRegisterStyle&WpEnqueueStyle&WpRegisterScript&WpEnqueueScript $wpService,
        private AcfService $acfService,
    ) {
        $this->init(...[
            new \ApiSponsorManager\Assignment\PostType($wpService),
            new \ApiSponsorManager\Activity\Taxonomy($wpService),
            new \ApiSponsorManager\Resource\Taxonomy($wpService),
        ]);
    }

    public function init(Hookable ...$hookables)
    {
        foreach ($hookables as $hookable) {
            $hookable->addHooks();
        }
    }
}
