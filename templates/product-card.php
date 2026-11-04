<?php

/**
 * @var WC_Product $product
 * @var int $salePercentage
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Internal template-only resolver: ensures the badge always
 * honors Woo Discount Rules percentage first, then the final
 * displayed price vs regular price, then WooCommerce sale price.
 */
$rsdpfResolvePercentage = static function (WC_Product $product): int {
    if ($product->is_type('variable')) {
        $percentages = [];

        foreach ($product->get_children() as $variationId) {
            $variation = wc_get_product($variationId);
            if (!$variation instanceof WC_Product) {
                continue;
            }

            $singlePercentage = 0;

            if (defined('WDR_VERSION')) {
                $wdrPercent = apply_filters('advanced_woo_discount_rules_get_product_discount_percentage', false, $variation);
                if ($wdrPercent !== false && is_numeric($wdrPercent)) {
                    $pct = (int) round((float) $wdrPercent);
                    if ($pct > 0 && $pct < 100) {
                        $singlePercentage = $pct;
                    }
                }
            }

            if ($singlePercentage <= 0) {
                $regular = (float) $variation->get_regular_price();
                $final   = (float) $variation->get_price();
                if ($regular > 0 && $final > 0 && $final < $regular) {
                    $singlePercentage = (int) round((($regular - $final) / $regular) * 100);
                }
            }

            if ($singlePercentage <= 0 && $variation->is_on_sale()) {
                $regular = (float) $variation->get_regular_price();
                $sale    = (float) $variation->get_sale_price();
                if ($regular > 0 && $sale > 0 && $sale < $regular) {
                    $singlePercentage = (int) round((($regular - $sale) / $regular) * 100);
                }
            }

            if ($singlePercentage > 0) {
                $percentages[] = $singlePercentage;
            }
        }

        return $percentages !== [] ? (int) max($percentages) : 0;
    }

    $singlePercentage = 0;

    if (defined('WDR_VERSION')) {
        $wdrPercent = apply_filters('advanced_woo_discount_rules_get_product_discount_percentage', false, $product);
        if ($wdrPercent !== false && is_numeric($wdrPercent)) {
            $pct = (int) round((float) $wdrPercent);
            if ($pct > 0 && $pct < 100) {
                $singlePercentage = $pct;
            }
        }
    }

    if ($singlePercentage <= 0) {
        $regular = (float) $product->get_regular_price();
        $final   = (float) $product->get_price();
        if ($regular > 0 && $final > 0 && $final < $regular) {
            $singlePercentage = (int) round((($regular - $final) / $regular) * 100);
        }
    }

    if ($singlePercentage <= 0 && $product->is_on_sale()) {
        $regular = (float) $product->get_regular_price();
        $sale    = (float) $product->get_sale_price();
        if ($regular > 0 && $sale > 0 && $sale < $regular) {
            $singlePercentage = (int) round((($regular - $sale) / $regular) * 100);
        }
    }

    return $singlePercentage;
};

$cardSalePercentage = $rsdpfResolvePercentage($product);

$productId = $product->get_id();
$image = $product->get_image('woocommerce_thumbnail', ['loading' => 'lazy']);
$title = $product->get_name();
$link = get_permalink($productId);
$priceHtml = $product->get_price_html();
$addToCartUrl = $product->add_to_cart_url();
$addToCartText = $product->add_to_cart_text();
$buttonClasses = implode(
    ' ',
    array_filter([
        'button',
        'product_type_' . $product->get_type(),
        $product->supports('ajax_add_to_cart') ? 'add_to_cart_button ajax_add_to_cart' : '',
    ])
);
?>
<article class="rsdpf-product-card" data-product-id="<?php echo esc_attr((string) $productId); ?>">
    <?php if ($cardSalePercentage > 0) : ?>
        <span class="rsdpf-sale-badge"><?php echo esc_html($cardSalePercentage . '%'); ?></span>
    <?php endif; ?>

    <a href="<?php echo esc_url($link); ?>" class="rsdpf-product-thumb">
        <?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped 
        ?>
    </a>

    <div class="rsdpf-card-body">
        <h3 class="rsdpf-product-title">
            <a href="<?php echo esc_url($link); ?>">
                <?php
                echo esc_html(
                    mb_strlen($title) > 30
                        ? mb_substr($title, 0, 30) . '...'
                        : $title
                );
                ?>
            </a>
        </h3>

        <div class="rsdpf-product-price">
            <?php echo wp_kses_post($priceHtml); ?>
        </div>

        <a
            href="<?php echo esc_url($addToCartUrl); ?>"
            data-quantity="1"
            data-product_id="<?php echo esc_attr((string) $productId); ?>"
            data-product_sku="<?php echo esc_attr($product->get_sku()); ?>"
            aria-label="<?php echo esc_attr($addToCartText); ?>"
            rel="nofollow"
            class="rsdpf-add-to-cart <?php echo esc_attr($buttonClasses); ?>">
            <?php esc_html_e('افزودن به سبد خرید', 'rsd-products-filter'); ?>
        </a>
    </div>
</article>