<?php

namespace ApiSponsorManager\Helper\Taxonomies;

use ApiSponsorManager\Helper\HooksRegistrar\Hookable;
use WpService\Contracts\__;
use WpService\Contracts\AddAction;
use WpService\Contracts\RegisterTaxonomy;

abstract class Taxonomy implements Hookable
{
    abstract public function getName(): string;

    abstract public function getObjectType(): string|array;

    abstract public function getArgs(): array;

    abstract public function getLabelSingular(): string;

    abstract public function getLabelPlural(): string;

    public function __construct(
        protected AddAction&RegisterTaxonomy&__ $wpService,
    ) {}

    public function addHooks(): void
    {
        $this->wpService->addAction('init', [$this, 'register']);
        $this->wpService->addAction('init', [$this, 'seed']);
    }

    public function register(): void
    {
        $args = array_merge($this->getArgs(), [
            'labels' => $this->getLabels(),
        ]);

        $this->wpService->registerTaxonomy($this->getName(), $this->getObjectType(), $args);
    }

    private function getLabels()
    {
        $labelSingular = $this->getLabelSingular();
        $labelPlural = $this->getLabelPlural();

        return [
            'name' => $labelPlural,
            'singular_name' => $labelSingular,
            'search_items' => sprintf($this->wpService->__('Search %s', 'api-sponsor-manager'), $labelPlural),
            'popular_items' => sprintf($this->wpService->__('Popular %s', 'api-sponsor-manager'), $labelPlural),
            'all_items' => sprintf($this->wpService->__('All %s', 'api-sponsor-manager'), $labelPlural),
            'parent_item' => sprintf($this->wpService->__('Parent %s', 'api-sponsor-manager'), $labelSingular),
            'parent_item_colon' => sprintf($this->wpService->__('Parent %s:', 'api-sponsor-manager'), $labelSingular),
            'edit_item' => sprintf($this->wpService->__('Edit %s', 'api-sponsor-manager'), $labelSingular),
            'update_item' => sprintf($this->wpService->__('Update %s', 'api-sponsor-manager'), $labelSingular),
            'add_new_item' => sprintf($this->wpService->__('Add new %s', 'api-sponsor-manager'), $labelSingular),
            'new_item_name' => sprintf($this->wpService->__('New %s name', 'api-sponsor-manager'), $labelSingular),
            'separate_items_with_commas' => sprintf($this->wpService->__('Separate %s with commas', 'api-sponsor-manager'), $labelPlural),
            'add_or_remove_items' => sprintf($this->wpService->__('Add or remove %s', 'api-sponsor-manager'), $labelPlural),
            'choose_from_most_used' => sprintf($this->wpService->__('Choose from most used %s', 'api-sponsor-manager'), $labelPlural),
            'not_found' => sprintf($this->wpService->__('No %s found', 'api-sponsor-manager'), $labelPlural),
            'menu_name' => $labelPlural,
        ];
    }

    public function seed(): void
    {
        foreach ($this->getSeed() as $term) {
            if (!term_exists($term, $this->getName())) {
                wp_insert_term($term, $this->getName());
            }
        }
    }

    /**
     * Seed data for the taxonomy.
     *
     * @return array
     */
    public function getSeed(): array
    {
        return [];
    }
}
