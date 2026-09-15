<?php

declare(strict_types=1);

namespace ApiSponsorManager;

use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use WpService\Contracts\AddFilter;

final class SponsorUploads implements Hookable
{
    public function __construct(private AddFilter $wpService) {}

    public function addHooks(): void
    {
        $this->wpService->addFilter('AcfRestUpload/destinations', [$this, 'destinations']);
    }

    public function destinations(array $destinations): array
    {
        return array_replace($destinations, [
            '/wp/v2/sponsor-assignments' => ['post_type' => 'assignment', 'image_field' => 'image'],
            '/wp/v2/sponsor-offerings' => ['post_type' => 'offering', 'image_field' => 'image'],
        ]);
    }
}
