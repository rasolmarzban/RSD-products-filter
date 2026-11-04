<?php

declare(strict_types=1);

namespace RSDPF;

use RSDPF\Domain\FilterRepository;
use RSDPF\Domain\ProductQuery;
use RSDPF\Frontend\Ajax;
use RSDPF\Frontend\Assets;
use RSDPF\Frontend\Shortcode;
use RSDPF\Support\Template;

final class Plugin
{
    public function boot(): void
    {
        $template = new Template(RSDPF_PATH . 'templates/');
        $filterRepository = new FilterRepository();
        $productQuery = new ProductQuery();
        $assets = new Assets();

        $shortcode = new Shortcode($template, $filterRepository, $productQuery, $assets);
        $ajax = new Ajax($shortcode);

        add_action('init', [$shortcode, 'register']);
        add_action('wp_enqueue_scripts', [$assets, 'register']);
        add_action('wp_enqueue_scripts', [$assets, 'enqueueBase'], 20);
        add_action('wp_ajax_rsdpf_filter_products', [$ajax, 'filterProducts']);
        add_action('wp_ajax_nopriv_rsdpf_filter_products', [$ajax, 'filterProducts']);
    }
}
