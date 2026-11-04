<?php

declare(strict_types=1);

namespace RSDPF\Frontend;

use RSDPF\Domain\FilterRepository;
use RSDPF\Domain\ProductQuery;
use RSDPF\Support\Template;
use WC_Product;
use WP_Query;
use WP_Term;

final class Shortcode
{
    private Template $template;
    private FilterRepository $filterRepository;
    private ProductQuery $productQuery;
    private Assets $assets;

    public function __construct(
        Template $template,
        FilterRepository $filterRepository,
        ProductQuery $productQuery,
        Assets $assets
    ) {
        $this->template = $template;
        $this->filterRepository = $filterRepository;
        $this->productQuery = $productQuery;
        $this->assets = $assets;
    }

    public function register(): void
    {
        add_shortcode('rsd_products_filter', [$this, 'render']);
    }

    /**
     * @param array<string, string> $atts
     */
    public function render(array $atts = []): string
    {
        $settings = shortcode_atts(
            [
                'per_page' => '9',
                'columns' => '3',
                'taxonomies' => '',
                'label_names' => '',
                'show_result_count' => 'yes',
            ],
            $atts,
            'rsd_products_filter'
        );

        $settings['per_page'] = (string) max(1, (int) $settings['per_page']);
        $settings['columns'] = (string) min(4, max(1, (int) $settings['columns']));

        $settings['taxonomies'] = $this->extendTaxonomiesWithCurrentArchive((string) $settings['taxonomies']);
        $customLabels = $this->parseCustomLabels((string) $settings['label_names']);
        $allowedTaxonomies = $this->filterRepository->getAllowedTaxonomyNames($settings['taxonomies']);
        $filterGroups = $this->filterRepository->getFilterGroups(
            $this->filterRepository->getTaxonomies($settings['taxonomies']),
            $customLabels
        );
        $state = $this->getDefaultState($allowedTaxonomies);
        $results = $this->productQuery->query($settings, $state, $allowedTaxonomies);

        $this->assets->enqueue([
            'columns' => (int) $settings['columns'],
            'allowed_taxonomies' => $allowedTaxonomies,
        ]);

        return $this->template->render(
            'filter-layout.php',
            [
                'settings' => $settings,
                'state' => $state,
                'filterGroups' => $filterGroups,
                'resultsHtml' => $this->renderProducts($results, (int) $settings['columns']),
                'priceRange' => $results['price_range'],
                'allowedTaxonomies' => $allowedTaxonomies,
                'showResultCount' => $settings['show_result_count'] === 'yes',
                'foundPosts' => $results['found_posts'],
            ]
        );
    }

    /**
     * @param array<string, mixed> $request
     */
    public function renderAjaxResponse(array $request): array
    {
        $settings = is_array($request['settings'] ?? null) ? $request['settings'] : [];
        $settings = [
            'per_page' => (string) max(1, (int) ($settings['per_page'] ?? 9)),
            'columns' => (string) min(4, max(1, (int) ($settings['columns'] ?? 3))),
            'taxonomies' => is_string($settings['taxonomies'] ?? null) ? (string) $settings['taxonomies'] : '',
            'show_result_count' => 'yes',
        ];

        $allowedTaxonomies = $this->filterRepository->getAllowedTaxonomyNames($settings['taxonomies']);
        $state = [
            'page' => max(1, (int) ($request['page'] ?? 1)),
            'min_price' => isset($request['min_price']) ? (float) $request['min_price'] : null,
            'max_price' => isset($request['max_price']) ? (float) $request['max_price'] : null,
            'search' => isset($request['search']) ? sanitize_text_field((string) $request['search']) : '',
            'tax_filters' => is_array($request['tax_filters'] ?? null) ? $request['tax_filters'] : [],
        ];

        $results = $this->productQuery->query($settings, $state, $allowedTaxonomies);

        return [
            'html' => $this->renderProducts($results, (int) $settings['columns']),
            'found_posts' => $results['found_posts'],
            'max_num_pages' => $results['max_num_pages'],
            'current_page' => $results['current_page'],
        ];
    }

    /**
     * @param array<int, string> $allowedTaxonomies
     * @return array<string, mixed>
     */
    private function getDefaultState(array $allowedTaxonomies): array
    {
        $taxFilters = [];

        foreach ($allowedTaxonomies as $taxonomy) {
            $candidates = [
                (string) $taxonomy,
                (string) urlencode((string) $taxonomy),
                (string) rawurlencode((string) $taxonomy),
            ];

            $resolvedKey = null;
            foreach ($candidates as $candidate) {
                if (isset($_GET[$candidate])) {
                    $resolvedKey = $candidate;
                    break;
                }
            }

            if ($resolvedKey === null) {
                $normalizedCandidates = [];
                foreach ($candidates as $candidate) {
                    $normalizedCandidates[$this->normalizeEncoding($candidate)] = true;
                }

                foreach (array_keys($_GET) as $getKey) {
                    $decodedKey = (string) $getKey;
                    if (isset($normalizedCandidates[$this->normalizeEncoding($decodedKey)])) {
                        $resolvedKey = $decodedKey;
                        break;
                    }
                    $decodedOnce = urldecode($decodedKey);
                    if (isset($normalizedCandidates[$this->normalizeEncoding($decodedOnce)])) {
                        $resolvedKey = $decodedKey;
                        break;
                    }
                    $decodedTwice = urldecode($decodedOnce);
                    if (isset($normalizedCandidates[$this->normalizeEncoding($decodedTwice)])) {
                        $resolvedKey = $decodedKey;
                        break;
                    }
                }
            }

            if ($resolvedKey !== null && isset($_GET[$resolvedKey])) {
                $termIds = (array) $_GET[$resolvedKey];
            } else {
                $termIds = [];
            }

            $taxFilters[$taxonomy] = array_values(array_filter(array_map('absint', $termIds)));
        }

        $queriedObject = get_queried_object();
        if ($queriedObject instanceof WP_Term && in_array($queriedObject->taxonomy, $allowedTaxonomies, true)) {
            if ($taxFilters[$queriedObject->taxonomy] === []) {
                $taxFilters[$queriedObject->taxonomy] = [(int) $queriedObject->term_id];
            }
        }

        return [
            'page' => max(1, (int) ($_GET['rsdpf_page'] ?? get_query_var('paged', 1))),
            'min_price' => isset($_GET['rsdpf_min_price']) ? (float) $_GET['rsdpf_min_price'] : null,
            'max_price' => isset($_GET['rsdpf_max_price']) ? (float) $_GET['rsdpf_max_price'] : null,
            'search' => $this->resolveSearchQuery(),
            'tax_filters' => $taxFilters,
        ];
    }

    private function normalizeEncoding(string $value): string
    {
        $value = rawurldecode($value);
        $value = preg_replace('~\s+~u', '', $value);
        if (function_exists('mb_strtolower')) {
            $lowered = @mb_strtolower($value, 'UTF-8');
            if ($lowered !== false && $lowered !== '') {
                return $lowered;
            }
        }
        return strtolower($value);
    }

    private function extendTaxonomiesWithCurrentArchive(string $requestedTaxonomies): string
    {
        $queriedObject = get_queried_object();
        if (!$queriedObject instanceof WP_Term || !$this->filterRepository->isAllowedTaxonomy($queriedObject->taxonomy)) {
            return $requestedTaxonomies;
        }

        $taxonomies = array_filter(array_map('trim', explode(',', $requestedTaxonomies)));
        if (!in_array($queriedObject->taxonomy, $taxonomies, true)) {
            $taxonomies[] = $queriedObject->taxonomy;
        }

        return implode(',', $taxonomies);
    }

    private function resolveSearchQuery(): string
    {
        if (isset($_GET['rsdpf_search'])) {
            $rsdpfSearch = (string) wp_unslash($_GET['rsdpf_search']);
            $attempts = [
                $rsdpfSearch,
                urldecode($rsdpfSearch),
                rawurldecode($rsdpfSearch),
                urldecode(urldecode($rsdpfSearch)),
            ];
            foreach ($attempts as $attempt) {
                $cleaned = sanitize_text_field((string) $attempt);
                if ($cleaned !== '') {
                    return $cleaned;
                }
            }
        }

        $searchQuery = isset($_GET['s']) ? sanitize_text_field((string) wp_unslash($_GET['s'])) : '';
        if ($searchQuery === '') {
            return '';
        }

        $requestedPostType = isset($_GET['post_type']) ? sanitize_key((string) wp_unslash($_GET['post_type'])) : '';
        $isProductSearch = $requestedPostType === 'product' || isset($_GET['dgwt_wcas']) || is_search();

        return $isProductSearch ? $searchQuery : '';
    }

    /**
     * @return array<string, string>
     */
    private function parseCustomLabels(string $rawLabels): array
    {
        $pairs = array_filter(array_map('trim', explode('|', $rawLabels)));
        $labels = [];

        foreach ($pairs as $pair) {
            $segments = array_map('trim', explode(':', $pair, 2));
            if (count($segments) !== 2) {
                continue;
            }

            [$taxonomy, $label] = $segments;
            $taxonomy = sanitize_key($taxonomy);
            $label = sanitize_text_field($label);

            if ($taxonomy === '' || $label === '') {
                continue;
            }

            $labels[$taxonomy] = $label;
        }

        return $labels;
    }

    /**
     * @param array<string, mixed> $results
     */
    private function renderProducts(array $results, int $columns): string
    {
        /** @var array<int, WC_Product> $products */
        $products = $results['products'];
        /** @var WP_Query $query */
        $query = $results['query'];

        return $this->template->render(
            'products-loop.php',
            [
                'products' => $products,
                'query' => $query,
                'columns' => $columns,
                'saleCalculator' => $this->productQuery,
                'view' => $this->template,
                'currentPage' => $results['current_page'],
            ]
        );
    }
}
