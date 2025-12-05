<?php

declare(strict_types=1);

namespace Tests\PlanetRide\SyliusAiAuditPlugin\Entity;

use Doctrine\ORM\Mapping as ORM;
use Sylius\Component\Core\Model\ProductTranslation as BaseProductTranslation;

#[ORM\Entity]
#[ORM\Table(name: 'sylius_product_translation')]
class ProductTranslation extends BaseProductTranslation
{
    #[ORM\Column(name: 'ai_audit_score', type: 'integer', nullable: true)]
    private ?int $aiAuditScore = null;

    #[ORM\Column(name: 'ai_audit_content', type: 'text', nullable: true)]
    private ?string $aiAuditContent = null;

    #[ORM\Column(name: 'ai_audit_updated_at', type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $aiAuditUpdatedAt = null;

    public function getAiAuditScore(): ?int
    {
        return $this->aiAuditScore;
    }

    public function setAiAuditScore(?int $aiAuditScore): void
    {
        $this->aiAuditScore = $aiAuditScore;
    }

    public function getAiAuditContent(): ?string
    {
        return $this->aiAuditContent;
    }

    public function setAiAuditContent(?string $aiAuditContent): void
    {
        $this->aiAuditContent = $aiAuditContent;
    }

    public function getAiAuditUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->aiAuditUpdatedAt;
    }

    public function setAiAuditUpdatedAt(?\DateTimeImmutable $aiAuditUpdatedAt): void
    {
        $this->aiAuditUpdatedAt = $aiAuditUpdatedAt;
    }
}
