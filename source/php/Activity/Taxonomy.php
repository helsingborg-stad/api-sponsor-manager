<?php

namespace ApiSponsorManager\Activity;

use ApiSponsorManager\Helper\Taxonomies\Taxonomy as CustomTaxonomy;

class Taxonomy extends CustomTaxonomy
{
    public function getName(): string
    {
        return 'activity';
    }

    public function getObjectType(): string
    {
        return 'assignment';
    }

    public function getArgs(): array
    {
        return array(
            'public' => true,
            'hierarchical' => true,
            'show_ui' => true,
            'meta_box_cb' => false,
            'show_in_rest' => true,
            'capabilities' => [
                'manage_terms' => 'administrator',
                'edit_terms' => 'administrator',
                'delete_terms' => 'administrator',
                'assign_terms' => 'administrator',
            ],
        );
    }

    public function getLabelSingular(): string
    {
        return $this->wpService->__('Activity', 'api-sponsor-manager');
    }

    public function getLabelPlural(): string
    {
        return $this->wpService->__('Activities', 'api-sponsor-manager');
    }
}
