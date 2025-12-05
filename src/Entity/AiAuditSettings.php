<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'ai_audit_settings')]
class AiAuditSettings
{
    #[ORM\Id]
    #[ORM\GeneratedValue(strategy: 'NONE')]
    #[ORM\Column(type: 'integer')]
    private int $id = 1;

    #[ORM\Column(name: 'system_prompt', type: 'text', nullable: true)]
    private ?string $systemPrompt = null;

    #[ORM\Column(name: 'user_prompt', type: 'text', nullable: true)]
    private ?string $userPrompt = null;

    #[ORM\Column(name: 'updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getSystemPrompt(): ?string
    {
        return $this->systemPrompt;
    }

    public function setSystemPrompt(?string $systemPrompt): void
    {
        $this->systemPrompt = $systemPrompt;
    }

    public function getUserPrompt(): ?string
    {
        return $this->userPrompt;
    }

    public function setUserPrompt(?string $userPrompt): void
    {
        $this->userPrompt = $userPrompt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }
}
