<?php

declare(strict_types=1);

namespace RSDPF\Frontend;

final class Ajax
{
    private Shortcode $shortcode;

    public function __construct(Shortcode $shortcode)
    {
        $this->shortcode = $shortcode;
    }

    public function filterProducts(): void
    {
        check_ajax_referer('rsdpf_filter_nonce', 'nonce');

        $payload = [];

        if (is_string($_POST['payload_json'] ?? null)) {
            $decodedPayload = json_decode(wp_unslash($_POST['payload_json']), true);
            if (is_array($decodedPayload)) {
                $payload = $decodedPayload;
            }
        } elseif (is_array($_POST['payload'] ?? null)) {
            $payload = $_POST['payload'];
        }

        $response = $this->shortcode->renderAjaxResponse($payload);

        wp_send_json_success($response);
    }
}
