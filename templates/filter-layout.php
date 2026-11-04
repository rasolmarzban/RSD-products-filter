<?php

/**
 * @var array<string, string> $settings
 * @var array<string, mixed> $state
 * @var array<int, array<string, mixed>> $filterGroups
 * @var string $resultsHtml
 * @var array<string, float> $priceRange
 * @var array<int, string> $allowedTaxonomies
 * @var bool $showResultCount
 * @var int $foundPosts
 */

$instanceId = 'rsdpf-' . wp_rand(1000, 999999);
$selectedFilters = is_array($state['tax_filters'] ?? null) ? $state['tax_filters'] : [];
$currentMinPrice = isset($state['min_price']) && $state['min_price'] !== null ? (float) $state['min_price'] : (float) $priceRange['min'];
$currentMaxPrice = isset($state['max_price']) && $state['max_price'] !== null ? (float) $state['max_price'] : (float) $priceRange['max'];

$renderTerms = static function (array $terms, string $taxonomy, array $selectedFilters, int $depth = 0) use (&$renderTerms): string {
    $html = '<ul class="rsdpf-term-list">';

    foreach ($terms as $term) {
        $termId = (int) ($term['id'] ?? 0);
        $termName = (string) ($term['name'] ?? '');
        $termCount = (int) ($term['count'] ?? 0);
        $children = is_array($term['children'] ?? null) ? $term['children'] : [];
        $isChecked = in_array($termId, $selectedFilters[$taxonomy] ?? [], true);

        $html .= '<li class="rsdpf-term-item rsdpf-term-depth-' . esc_attr((string) $depth) . '">';
        $html .= '<label class="rsdpf-check rsdpf-check-depth-' . esc_attr((string) $depth) . '">';
        $html .= '<input type="checkbox" class="rsdpf-filter-input" data-taxonomy="' . esc_attr($taxonomy) . '" value="' . esc_attr((string) $termId) . '"' . checked($isChecked, true, false) . ' />';
        $html .= '<span class="rsdpf-check-label">' . esc_html($termName) . '</span>';
        // $html .= '<span class="rsdpf-check-count">' . esc_html((string) $termCount) . '</span>';
        $html .= '</label>';

        if ($children !== [] && $depth < 2) {
            $html .= $renderTerms($children, $taxonomy, $selectedFilters, $depth + 1);
        }

        $html .= '</li>';
    }

    $html .= '</ul>';

    return $html;
};

$allowedTermHtml = [
    'ul' => [
        'class' => true,
    ],
    'li' => [
        'class' => true,
    ],
    'label' => [
        'class' => true,
    ],
    'input' => [
        'type' => true,
        'class' => true,
        'data-taxonomy' => true,
        'value' => true,
        'checked' => true,
    ],
    'span' => [
        'class' => true,
    ],
];
?>
<div
    id="<?php echo esc_attr($instanceId); ?>"
    class="rsdpf"
    data-settings="<?php echo esc_attr(wp_json_encode($settings)); ?>"
    data-search="<?php echo esc_attr((string) ($state['search'] ?? '')); ?>"
    data-price-min="<?php echo esc_attr((string) $priceRange['min']); ?>"
    data-price-max="<?php echo esc_attr((string) $priceRange['max']); ?>">
    <div class="rsdpf-mobile-toolbar">
        <button type="button" class="rsdpf-mobile-open">
            <?php esc_html_e('فیلتر', 'rsd-products-filter'); ?>
        </button>
    </div>
    <div class="rsdpf-mobile-overlay"></div>
    <div class="rsdpf-layout">
        <aside class="rsdpf-sidebar">
            <div class="rsdpf-mobile-head">
                <strong class="rsdpf-mobile-title"><?php esc_html_e('فیلتر محصولات', 'rsd-products-filter'); ?></strong>
                <button type="button" class="rsdpf-mobile-close" aria-label="<?php esc_attr_e('بستن فیلتر', 'rsd-products-filter'); ?>">
                    ×
                </button>
            </div>
            <div class="rsdpf-panel">
                <?php foreach ($filterGroups as $group) : ?>
                    <section class="rsdpf-filter-box" data-taxonomy="<?php echo esc_attr((string) $group['taxonomy']); ?>">
                        <button type="button" class="rsdpf-box-toggle">
                            <span><?php echo esc_html((string) $group['label']); ?></span>
                            <span class="rsdpf-toggle-icon" aria-hidden="true"></span>
                        </button>

                        <div class="rsdpf-box-body">
                            <div class="rsdpf-search">
                                <input type="search" class="rsdpf-term-search" placeholder="<?php echo esc_attr__('جستجو...', 'rsd-products-filter'); ?>" />
                            </div>
                            <div class="rsdpf-terms-wrap">
                                <?php echo wp_kses($renderTerms((array) $group['terms'], (string) $group['taxonomy'], $selectedFilters, 0), $allowedTermHtml); ?>
                            </div>
                        </div>
                    </section>
                <?php endforeach; ?>

                <section class="rsdpf-filter-box">
                    <button type="button" class="rsdpf-box-toggle">
                        <span><?php esc_html_e('قیمت', 'rsd-products-filter'); ?></span>
                        <span class="rsdpf-toggle-icon" aria-hidden="true"></span>
                    </button>

                    <div class="rsdpf-box-body">
                        <div class="rsdpf-price-slider">
                            <div class="rsdpf-slider-track"></div>
                            <div class="rsdpf-slider-progress"></div>
                            <input
                                type="range"
                                class="rsdpf-range rsdpf-range-min"
                                min="<?php echo esc_attr((string) $priceRange['min']); ?>"
                                max="<?php echo esc_attr((string) $priceRange['max']); ?>"
                                step="1000"
                                value="<?php echo esc_attr((string) $currentMinPrice); ?>" />
                            <input
                                type="range"
                                class="rsdpf-range rsdpf-range-max"
                                min="<?php echo esc_attr((string) $priceRange['min']); ?>"
                                max="<?php echo esc_attr((string) $priceRange['max']); ?>"
                                step="1000"
                                value="<?php echo esc_attr((string) $currentMaxPrice); ?>" />
                        </div>
                        <div class="rsdpf-price-values">
                            <span class="rsdpf-price-label"><?php echo esc_html(number_format_i18n((float) $currentMinPrice)); ?></span>
                            <span class="rsdpf-price-separator">-</span>
                            <span class="rsdpf-price-label"><?php echo esc_html(number_format_i18n((float) $currentMaxPrice)); ?></span>
                            <span class="rsdpf-price-currency"><?php esc_html_e('تومان', 'rsd-products-filter'); ?></span>
                        </div>
                    </div>
                </section>

                <button type="button" class="rsdpf-reset"><?php esc_html_e('حذف فیلتر', 'rsd-products-filter'); ?></button>
                <button type="button" class="rsdpf-mobile-apply"><?php esc_html_e('مشاهده نتایج', 'rsd-products-filter'); ?></button>
            </div>
        </aside>

        <div class="rsdpf-content">
            <?php if ($showResultCount) : ?>
                <div class="rsdpf-toolbar">
                    <div class="rsdpf-result-count">
                        <?php
                        printf(
                            wp_kses_post(__('%s محصول', 'rsd-products-filter')),
                            '<span class="rsdpf-found-count">' . esc_html(number_format_i18n($foundPosts)) . '</span>'
                        );
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <div class="rsdpf-results">
                <?php echo $resultsHtml; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
                ?>
            </div>
        </div>
    </div>
</div>
