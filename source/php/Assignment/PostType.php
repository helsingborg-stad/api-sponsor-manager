<?php

namespace ApiSponsorManager\Assignment;

use ApiSponsorManager\Helper\PostTypes\Icons\Icon;
use ApiSponsorManager\Helper\PostTypes\PostType as CustomPostType;

class PostType extends CustomPostType
{
    public function getName(): string
    {
        return 'assignment';
    }

    public function getArgs(): array
    {
        return [
            'show_in_rest' => true,
            'public' => true,
            'hierarchical' => true,
            'menu_icon' => (new Icon('Event'))->getIcon(),
            'rest_base' => 'sponsor-assignments',
            // 'rest_controller_class' => \ApiSponsorManager\,
            'supports' => ['title', 'revisions'],
        ];
    }

    public function getLabelSingular(): string
    {
        return $this->wpService->__('Assignment', 'api-sponsor-manager');
    }

    public function getLabelPlural(): string
    {
        return $this->wpService->__('Assignments', 'api-sponsor-manager');
    }
}
