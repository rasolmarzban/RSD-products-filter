<?php

declare(strict_types=1);

namespace RSDPF\Domain;

use WP_Taxonomy;
use WP_Term;

final class FilterRepository
{
    /**
     * @return array<int, WP_Taxonomy>
     */
    public function getTaxonomies(string $requestedTaxonomies = ''): array
    {
        $requested = array_filter(array_map('trim', explode(',', $requestedTaxonomies)));
        $taxonomies = [];

        foreach ($this->getDefaultTaxonomyOrder() as $taxonomy) {
            if (($requested !== []) && !in_array($taxonomy, $requested, true)) {
                continue;
            }

            if (!$this->isAllowedTaxonomy($taxonomy)) {
                continue;
            }

            $taxonomyObject = get_taxonomy($taxonomy);
            if ($taxonomyObject instanceof WP_Taxonomy) {
                $taxonomies[] = $taxonomyObject;
            }
        }

        if ($requested !== []) {
            foreach ($requested as $taxonomy) {
                if (!$this->isAllowedTaxonomy($taxonomy) || in_array($taxonomy, array_map(static fn(WP_Taxonomy $item): string => $item->name, $taxonomies), true)) {
                    continue;
                }

                $taxonomyObject = get_taxonomy($taxonomy);
                if ($taxonomyObject instanceof WP_Taxonomy) {
                    $taxonomies[] = $taxonomyObject;
                }
            }
        }

        return $taxonomies;
    }

    /**
     * @param array<int, WP_Taxonomy> $taxonomies
     * @param array<string, string> $customLabels
     * @return array<int, array<string, mixed>>
     */
    public function getFilterGroups(array $taxonomies, array $customLabels = []): array
    {
        $groups = [];

        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => $taxonomy->name,
                'hide_empty' => true,
                'orderby' => $taxonomy->hierarchical ? 'name' : 'count',
                'order' => 'ASC',
            ]);

            if (!is_array($terms) || $terms === []) {
                continue;
            }

            $groups[] = [
                'taxonomy' => $taxonomy->name,
                'label' => $customLabels[$taxonomy->name] ?? ($taxonomy->labels->singular_name ?: $taxonomy->label),
                'hierarchical' => (bool) $taxonomy->hierarchical,
                'terms' => $taxonomy->hierarchical ? $this->buildHierarchy($terms) : $this->normalizeFlatTerms($terms),
            ];
        }

        return $groups;
    }

    public function isAllowedTaxonomy(string $taxonomy): bool
    {
        if (!taxonomy_exists($taxonomy)) {
            return false;
        }

        if ($taxonomy === 'product_cat') {
            return true;
        }

        return strpos($taxonomy, 'pa_') === 0;
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedTaxonomyNames(string $requestedTaxonomies = ''): array
    {
        return array_map(
            static fn(WP_Taxonomy $taxonomy): string => $taxonomy->name,
            $this->getTaxonomies($requestedTaxonomies)
        );
    }

    /**
     * @return array<int, string>
     */
    private function getDefaultTaxonomyOrder(): array
    {
        $preferred = ['product_cat', 'pa_مولف', 'pa_ناشر'];
        $attributes = wc_get_attribute_taxonomies();

        if (!is_array($attributes)) {
            return $preferred;
        }

        foreach ($attributes as $attribute) {
            $taxonomyName = wc_attribute_taxonomy_name($attribute->attribute_name);
            if (!in_array($taxonomyName, $preferred, true)) {
                $preferred[] = $taxonomyName;
            }
        }

        return $preferred;
    }

    /**
     * @param array<int, WP_Term> $terms
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFlatTerms(array $terms): array
    {
        $items = [];

        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }

            $items[] = [
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'count' => (int) $term->count,
                'children' => [],
            ];
        }

        return $items;
    }

    /**
     * @param array<int, WP_Term> $terms
     * @return array<int, array<string, mixed>>
     */
    private function buildHierarchy(array $terms): array
    {
        $indexed = [];

        foreach ($terms as $term) {
            if (!$term instanceof WP_Term) {
                continue;
            }

            $indexed[(int) $term->term_id] = [
                'id' => (int) $term->term_id,
                'name' => $term->name,
                'count' => (int) $term->count,
                'parent' => (int) $term->parent,
                'children' => [],
            ];
        }

        $tree = [];

        foreach ($indexed as $termId => $node) {
            if ($node['parent'] > 0 && isset($indexed[$node['parent']])) {
                $indexed[$node['parent']]['children'][] = &$indexed[$termId];
                continue;
            }

            $tree[] = &$indexed[$termId];
        }

        return array_map([$this, 'stripParentKey'], $tree);
    }

    /**
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function stripParentKey(array $node): array
    {
        unset($node['parent']);

        if ($node['children'] === []) {
            return $node;
        }

        $node['children'] = array_map([$this, 'stripParentKey'], $node['children']);

        return $node;
    }
}
