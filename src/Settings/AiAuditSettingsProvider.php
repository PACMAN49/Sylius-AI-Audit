<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\Settings;

use Doctrine\ORM\EntityManagerInterface;
use PlanetRide\SyliusAiAuditPlugin\Entity\AiAuditSettings;

final class AiAuditSettingsProvider
{
    private const DEFAULT_SYSTEM_PROMPT = "Tu es charge d'auditer une fiche produit Planet Ride. Produis un score et une liste d'actions.";
    private const DEFAULT_USER_PROMPT = 'Voici les contenus a auditer. Retourne le score et la liste des problemes.';

    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
    }

    public function getSettings(): AiAuditSettings
    {
        $repository = $this->entityManager->getRepository(AiAuditSettings::class);
        $settings = $repository->find(1);

        if ($settings === null) {
            $settings = new AiAuditSettings();
            $settings->setSystemPrompt(self::DEFAULT_SYSTEM_PROMPT);
            $settings->setUserPrompt(self::DEFAULT_USER_PROMPT);
            $settings->setUpdatedAt(new \DateTimeImmutable());

            $this->entityManager->persist($settings);
            $this->entityManager->flush();
        }

        return $settings;
    }

    public function update(?string $systemPrompt, ?string $userPrompt): AiAuditSettings
    {
        $settings = $this->getSettings();
        $settings->setSystemPrompt($systemPrompt);
        $settings->setUserPrompt($userPrompt);
        $settings->setUpdatedAt(new \DateTimeImmutable());

        $this->entityManager->flush();

        return $settings;
    }
}


