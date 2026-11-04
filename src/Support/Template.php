<?php

declare(strict_types=1);

namespace RSDPF\Support;

final class Template
{
    private string $basePath;

    public function __construct(string $basePath)
    {
        $this->basePath = trailingslashit($basePath);
    }

    public function render(string $template, array $data = []): string
    {
        $templatePath = $this->basePath . ltrim($template, '/');

        if (!file_exists($templatePath)) {
            return '';
        }

        extract($data, EXTR_SKIP);

        ob_start();
        include $templatePath;

        return (string) ob_get_clean();
    }
}
