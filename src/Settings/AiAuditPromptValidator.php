<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\Settings;

final class AiAuditPromptValidator
{
    public function __construct(
        private readonly AiAuditPromptVariables $variables,
        private readonly AiAuditPromptRenderer $renderer,
    ) {
    }

    /**
     * @return string[] invalid variables
     */
    public function validate(string $template): array
    {
        $allowed = $this->variables->getAllowedVariables();
        $found = $this->renderer->extractVariables($template);

        return array_values(array_diff($found, $allowed));
    }

    /**
     * @return string[] variables present in the template
     */
    public function extract(string $template): array
    {
        return $this->renderer->extractVariables($template);
    }
}
