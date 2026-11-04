<?php

declare(strict_types=1);

namespace RSDPF\Domain;

use WP_Query;
use WC_Product;
use WC_Product_Variable;

final class ProductQuery
{
    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $state
     * @param array<int, string> $allowedTaxonomies
     * @return array<string, mixed>
     */
    public function query(array $settings, array $state, array $allowedTaxonomies): array
    {
        $page = max(1, (int) ($state['page'] ?? 1));
        $perPage = max(1, (int) ($settings['per_page'] ?? 9));
        $priceRange = $this->getPriceBounds();

        $queryArgs = [
            'post_type' => 'product',
            'post_status' => 'publish',
            'paged' => $page,
            'posts_per_page' => $perPage,
            'ignore_sticky_posts' => true,
            'meta_query' => WC()->query->get_meta_query(),
            'tax_query' => WC()->query->get_tax_query(),
        ];

        $searchQuery = isset($state['search']) && is_string($state['search']) ? trim((string) $state['search']) : '';
        $matchingProductIds = [];
        if ($searchQuery !== '') {
            $matchingProductIds = $this->getMatchingProductIdsForSearch($searchQuery);
            $queryArgs['post__in'] = $matchingProductIds === [] ? [0] : $matchingProductIds;
        }

        $selectedTerms = $this->normalizeTaxFilters($state['tax_filters'] ?? [], $allowedTaxonomies);

        foreach ($selectedTerms as $taxonomy => $termIds) {
            $queryArgs['tax_query'][] = [
                'taxonomy' => $taxonomy,
                'field' => 'term_id',
                'terms' => $termIds,
                'operator' => 'IN',
                'include_children' => true,
            ];
        }

        $minPrice = isset($state['min_price']) ? (float) $state['min_price'] : (float) $priceRange['min'];
        $maxPrice = isset($state['max_price']) ? (float) $state['max_price'] : (float) $priceRange['max'];

        if ($minPrice > $priceRange['min'] || $maxPrice < $priceRange['max']) {
            $queryArgs['meta_query'][] = [
                'key' => '_price',
                'value' => [$minPrice, $maxPrice],
                'compare' => 'BETWEEN',
                'type' => 'NUMERIC',
            ];
        }

        $queryArgs['rsdpf_custom_ordering'] = true;
        $queryArgs['rsdpf_search_ids'] = $matchingProductIds;

        add_filter('posts_clauses', [$this, 'filterPostsClauses'], 10, 2);
        $query = new WP_Query($queryArgs);
        remove_filter('posts_clauses', [$this, 'filterPostsClauses'], 10);
        $products = [];

        foreach ($query->posts as $post) {
            $product = wc_get_product($post->ID);
            if ($product instanceof WC_Product) {
                $products[] = $product;
            }
        }

        return [
            'products' => $products,
            'query' => $query,
            'found_posts' => (int) $query->found_posts,
            'max_num_pages' => (int) $query->max_num_pages,
            'current_page' => $page,
            'price_range' => $priceRange,
        ];
    }

    /**
     * @return array<string, float>
     */
    public function getPriceBounds(): array
    {
        global $wpdb;

        $lookupTable = $wpdb->prefix . 'wc_product_meta_lookup';
        $tableExists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $lookupTable));

        if ($tableExists === $lookupTable) {
            $lookupCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$lookupTable}");

            if ($lookupCount > 0) {
                $row = $wpdb->get_row(
                    "SELECT MIN(min_price) AS min_price, MAX(max_price) AS max_price
                FROM {$lookupTable}
                WHERE product_id IN (
                    SELECT ID FROM {$wpdb->posts}
                    WHERE post_type = 'product' AND post_status = 'publish'
                )",
                    ARRAY_A
                );

                return [
                    'min' => max(0.0, (float) ($row['min_price'] ?? 0)),
                    'max' => max(0.0, (float) ($row['max_price'] ?? 0)),
                ];
            }
        }

        $row = $wpdb->get_row(
            "SELECT MIN(CAST(pm.meta_value AS DECIMAL(12,2))) AS min_price, MAX(CAST(pm.meta_value AS DECIMAL(12,2))) AS max_price
            FROM {$wpdb->postmeta} pm
            INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE pm.meta_key = '_price'
            AND p.post_type = 'product'
            AND p.post_status = 'publish'
            AND CAST(pm.meta_value AS DECIMAL(12,2)) > 0",
            ARRAY_A
        );

        $minPrice = (float) ($row['min_price'] ?? 0);
        $maxPrice = (float) ($row['max_price'] ?? 0);

        if ($minPrice <= 0 || $maxPrice <= 0) {
            $fallbackRow = $wpdb->get_row(
                "SELECT MIN(CAST(pm.meta_value AS DECIMAL(12,2))) AS min_price, MAX(CAST(pm.meta_value AS DECIMAL(12,2))) AS max_price
                FROM {$wpdb->postmeta} pm
                INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                WHERE pm.meta_key = '_price'
                AND p.post_type = 'product'
                AND p.post_status = 'publish'",
                ARRAY_A
            );

            $minPrice = (float) ($fallbackRow['min_price'] ?? 0);
            $maxPrice = (float) ($fallbackRow['max_price'] ?? 0);
        }

        return [
            'min' => max(0.0, $minPrice),
            'max' => max(0.0, $maxPrice),
        ];
    }

    public function getSalePercentage(WC_Product $product): int
    {
        if ($product->is_type('variable')) {
            $percentages = [];

            foreach ($product->get_children() as $variationId) {
                $variation = wc_get_product($variationId);
                if (!$variation instanceof WC_Product) {
                    continue;
                }

                $pct = $this->calculateSingleProductDiscount($variation);
                if ($pct > 0) {
                    $percentages[] = $pct;
                }
            }

            return $percentages !== [] ? (int) max($percentages) : 0;
        }

        return $this->calculateSingleProductDiscount($product);
    }

    private function calculateSingleProductDiscount(WC_Product $product): int
    {
        // 1) Woo Discount Rules (WDR) official filter.
        if (defined('WDR_VERSION')) {
            $wdrPercent = apply_filters('advanced_woo_discount_rules_get_product_discount_percentage', false, $product);

            if ($wdrPercent !== false && is_numeric($wdrPercent)) {
                $pct = (int) round((float) $wdrPercent);
                if ($pct > 0 && $pct < 100) {
                    return $pct;
                }
            }
        }

        // 2) Universal fallback: regular_price vs final displayed price.
        //    get_price() includes discounts from WDR and any hooked plugin.
        $regularPrice = (float) $product->get_regular_price();
        $finalPrice   = (float) $product->get_price();

        if ($regularPrice > 0 && $finalPrice > 0 && $finalPrice < $regularPrice) {
            return (int) round((($regularPrice - $finalPrice) / $regularPrice) * 100);
        }

        // 3) Standard WooCommerce on-sale logic.
        if ($product->is_on_sale()) {
            $salePrice = (float) $product->get_sale_price();
            if ($regularPrice > 0 && $salePrice > 0 && $salePrice < $regularPrice) {
                return (int) round((($regularPrice - $salePrice) / $regularPrice) * 100);
            }
        }

        return 0;
    }

    /**
     * @param array<string, string> $clauses
     * @return array<string, string>
     */
    public function filterPostsClauses(array $clauses, WP_Query $query): array
    {
        if (!$query->get('rsdpf_custom_ordering')) {
            return $clauses;
        }

        global $wpdb;

        $postsTable = $wpdb->posts;
        $postmetaTable = $wpdb->postmeta;

        $stockStatusExpression = "COALESCE((
            SELECT pm_stock.meta_value
            FROM {$postmetaTable} pm_stock
            WHERE pm_stock.post_id = {$postsTable}.ID
              AND pm_stock.meta_key = '_stock_status'
            LIMIT 1
        ), 'outofstock')";

        $priceExpression = "COALESCE((
            SELECT CAST(pm_price.meta_value AS DECIMAL(12,2))
            FROM {$postmetaTable} pm_price
            WHERE pm_price.post_id = {$postsTable}.ID
              AND pm_price.meta_key = '_price'
            LIMIT 1
        ), 0)";

        $searchIds = $query->get('rsdpf_search_ids');
        $searchOrderClause = '';

        if (is_array($searchIds) && $searchIds !== []) {
            $searchIds = array_values(array_filter(array_map('absint', $searchIds)));
            if ($searchIds !== []) {
                $searchOrderClause = 'FIELD(' . $postsTable . '.ID, ' . implode(',', $searchIds) . ') ASC, ';
            }
        }

        $clauses['orderby'] = "
            CASE
                WHEN {$stockStatusExpression} = 'instock' AND {$priceExpression} > 0 THEN 0
                WHEN {$stockStatusExpression} = 'instock' THEN 1
                WHEN {$priceExpression} > 0 THEN 2
                ELSE 3
            END ASC,
            {$searchOrderClause}
            {$postsTable}.menu_order ASC,
            {$postsTable}.post_title ASC
        ";

        return $clauses;
    }

    /**
     * @return array<int, int>
     */
    private function getMatchingProductIdsForSearch(string $searchQuery): array
    {
        global $wpdb;

        $searchQuery = trim($searchQuery);
        if ($searchQuery === '') {
            return [];
        }

        $terms = preg_split('/\s+/u', $searchQuery) ?: [];
        $terms = array_values(array_filter(array_map('trim', $terms)));

        $phraseCondition = $wpdb->prepare('p.post_title LIKE %s', '%' . $wpdb->esc_like($searchQuery) . '%');
        $termConditions = [];

        foreach ($terms as $term) {
            $termConditions[] = $wpdb->prepare('p.post_title LIKE %s', '%' . $wpdb->esc_like($term) . '%');
        }

        $whereParts = [$phraseCondition];
        if ($termConditions !== []) {
            $whereParts[] = '(' . implode(' AND ', $termConditions) . ')';
        }

        $sql = "
            SELECT p.ID
            FROM {$wpdb->posts} p
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
              AND (" . implode(' OR ', $whereParts) . ')
            ORDER BY p.menu_order ASC, p.post_title ASC
        ';

        $results = $wpdb->get_col($sql);

        return array_values(array_filter(array_map('absint', is_array($results) ? $results : [])));
    }

    /**
     * @param mixed $rawFilters
     * @param array<int, string> $allowedTaxonomies
     * @return array<string, array<int, int>>
     */
    private function normalizeTaxFilters($rawFilters, array $allowedTaxonomies): array
    {
        if (!is_array($rawFilters)) {
            return [];
        }

        $normalized = [];

        foreach ($rawFilters as $taxonomy => $termIds) {
            if (!is_string($taxonomy) || !in_array($taxonomy, $allowedTaxonomies, true) || !is_array($termIds)) {
                continue;
            }

            $sanitizedTermIds = array_values(array_filter(array_map('absint', $termIds)));
            if ($sanitizedTermIds === []) {
                continue;
            }

            $normalized[$taxonomy] = $sanitizedTermIds;
        }

        return $normalized;
    }
}
