<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\Settings;

use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;

final class AiAuditPromptVariables
{
    /** @return string[] */
    public function getAllowedVariables(): array
    {
        return [
            'name',
            'description',
            'shortDescription',
            'metaDescription',
            'metaKeywords',
            'productId',
            'code',
            'productData',
        ];
    }

    /** @return array<string,string> */
    public function buildContext(ProductInterface $product, ProductTranslationInterface $translation): array
    {
        $shortDescription = method_exists($translation, 'getShortDescription') ? (string) $translation->getShortDescription() : '';

        return [
            'name' => (string) $translation->getName(),
            'description' => (string) $translation->getDescription(),
            'shortDescription' => $shortDescription,
            'metaDescription' => (string) $translation->getMetaDescription(),
            'metaKeywords' => (string) $translation->getMetaKeywords(),
            'productId' => (string) $product->getId(),
            'code' => method_exists($product, 'getCode') ? (string) $product->getCode() : '',
            'productData' => '',
        ];
    }
}
