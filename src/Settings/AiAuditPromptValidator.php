<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\Settings;

use Psr\Log\LoggerInterface;

final class AiAuditPromptValidator
{
    public function __construct(
        private readonly AiAuditPromptVariables $variables,
        private readonly AiAuditPromptRenderer $renderer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return string[] invalid variables
     */
    public function validate(string $template): array
    {
        $found = $this->renderer->extractVariables($template);
        $allowed = $this->variables->getAllowedVariables();

        $invalid = array_values(array_diff($found, $allowed));

        $this->logger->debug('[AiAudit] Validation des variables du prompt', [
            'template_length' => strlen($template),
            'found_variables' => $found,
            'allowed_variables_count' => count($allowed),
            'invalid_variables' => $invalid,
        ]);

        return $invalid;
    }

    /**
     * @return string[] variables present in the template
     */
    public function extract(string $template): array
    {
        return $this->renderer->extractVariables($template);
    }
}
