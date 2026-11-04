<?php

declare(strict_types=1);

namespace RSDPF\Frontend;

final class Assets
{
    private bool $localized = false;

    public function register(): void
    {
        $cssVersion = RSDPF_VERSION;
        $jsVersion = RSDPF_VERSION;

        $cssPath = RSDPF_PATH . 'assets/css/frontend.css';
        $jsPath = RSDPF_PATH . 'assets/js/frontend.js';

        if (file_exists($cssPath)) {
            $cssVersion = (string) filemtime($cssPath);
        }

        if (file_exists($jsPath)) {
            $jsVersion = (string) filemtime($jsPath);
        }

        wp_register_style(
            'rsdpf-frontend',
            RSDPF_URL . 'assets/css/frontend.css',
            [],
            $cssVersion
        );

        wp_register_script(
            'rsdpf-frontend',
            RSDPF_URL . 'assets/js/frontend.js',
            [],
            $jsVersion,
            true
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    public function enqueue(array $config): void
    {
        if (!wp_style_is('rsdpf-frontend', 'registered') || !wp_script_is('rsdpf-frontend', 'registered')) {
            $this->register();
        }

        wp_enqueue_style('rsdpf-frontend');
        wp_enqueue_script('rsdpf-frontend');

        $allowedTaxonomies = $config['allowed_taxonomies'] ?? [];
        $allowedTaxonomies = is_array($allowedTaxonomies) ? array_values($allowedTaxonomies) : [];

        if (!$this->localized) {
            wp_localize_script(
                'rsdpf-frontend',
                'RSDPF',
                [
                    'ajaxUrl' => admin_url('admin-ajax.php'),
                    'nonce' => wp_create_nonce('rsdpf_filter_nonce'),
                    'allowedTaxonomies' => $allowedTaxonomies,
                    'i18n' => [
                        'loading' => __('در حال بارگذاری...', 'rsd-products-filter'),
                        'empty' => __('محصولی با این فیلترها پیدا نشد.', 'rsd-products-filter'),
                    ],
                ]
            );
            $this->localized = true;
        }
    }

    public function enqueueBase(): void
    {
        if (is_admin()) {
            return;
        }

        $this->enqueue([]);
    }
}
