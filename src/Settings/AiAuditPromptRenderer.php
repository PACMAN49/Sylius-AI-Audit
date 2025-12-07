<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\Settings;

final class AiAuditPromptRenderer
{
    private const TOKEN_PATTERN = '/{{\s*([a-zA-Z0-9_]+)\s*}}/';

    /**
     * @param string $template Prompt with placeholders {{variable}}
     * @param array<string, string> $context Map of variable => value
     */
    public function render(string $template, array $context): string
    {
        return (string) preg_replace_callback(self::TOKEN_PATTERN, static function (array $matches) use ($context): string {
            $key = $matches[1];

            return $context[$key] ?? '';
        }, $template);
    }

    /**
     * @return string[] list of variables found in the template
     */
    public function extractVariables(string $template): array
    {
        if (!preg_match_all(self::TOKEN_PATTERN, $template, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1]));
    }
}
