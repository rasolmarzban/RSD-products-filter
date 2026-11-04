<?php

/**
 * @var array<int, WC_Product> $products
 * @var WP_Query $query
 * @var int $columns
 * @var RSDPF\Domain\ProductQuery $saleCalculator
 * @var RSDPF\Support\Template $view
 * @var int $currentPage
 */

if (!defined('ABSPATH')) {
    exit;
}
?>
<div class="rsdpf-results-inner" data-found-posts="<?php echo esc_attr((string) $query->found_posts); ?>">
    <?php if ($products === []) : ?>
        <div class="rsdpf-empty-state">
            <?php esc_html_e('محصولی با این فیلترها پیدا نشد.', 'rsd-products-filter'); ?>
        </div>
    <?php else : ?>
        <div class="rsdpf-products rsdpf-columns-<?php echo esc_attr((string) $columns); ?>">
            <?php foreach ($products as $product) : ?>
                <?php
                echo $view->render(
                    'product-card.php',
                    [
                        'product' => $product,
                        'salePercentage' => $saleCalculator->getSalePercentage($product),
                    ]
                ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
                ?>
            <?php endforeach; ?>
        </div>

        <?php if ($query->max_num_pages > 1) : ?>
            <nav class="rsdpf-pagination" aria-label="<?php esc_attr_e('صفحه بندی محصولات', 'rsd-products-filter'); ?>">
                <?php
                $totalPages = (int) $query->max_num_pages;
                $currentPage = max(1, min($totalPages, (int) $currentPage));

                if ($totalPages <= 5) {
                    $pages = range(1, $totalPages);
                } elseif ($currentPage <= 3) {
                    $pages = [1, 2, 3, 4, $totalPages];
                } elseif ($currentPage >= $totalPages - 2) {
                    $pages = [1, $totalPages - 3, $totalPages - 2, $totalPages - 1, $totalPages];
                } else {
                    $pages = [1, $currentPage - 1, $currentPage, $currentPage + 1, $totalPages];
                }

                $pages = array_values(array_unique(array_filter($pages, static fn(int $page): bool => $page >= 1 && $page <= $totalPages)));
                sort($pages);

                $previous = 0;
                foreach ($pages as $page) :
                    if ($previous > 0 && $page - $previous > 1) :
                ?>
                        <span class="rsdpf-page-ellipsis">…</span>
                    <?php
                    endif;

                    $previous = $page;
                    ?>
                    <button
                        type="button"
                        class="rsdpf-page-btn<?php echo $page === $currentPage ? ' is-active' : ''; ?>"
                        data-page="<?php echo esc_attr((string) $page); ?>">
                        <?php echo esc_html((string) $page); ?>
                    </button>
                <?php endforeach; ?>
            </nav>
        <?php endif; ?>
    <?php endif; ?>
</div>