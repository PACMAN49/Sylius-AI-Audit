<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\Settings;

use Psr\Log\LoggerInterface;
use Sylius\Component\Core\Model\ProductInterface;
use Sylius\Component\Core\Model\ProductTranslationInterface;
use Sylius\Component\Product\Repository\ProductAttributeRepositoryInterface;

final class AiAuditPromptVariables
{
    private const ATTRIBUTE_PREFIX = 'attribute_';

    public function __construct(
        private readonly ProductAttributeRepositoryInterface $productAttributeRepository,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return string[] */
    public function getAllowedVariables(): array
    {
        return array_merge(
            $this->getBaseVariables(),
            $this->getAttributeVariables(),
        );
    }

    public function isAllowed(string $variable): bool
    {
        if (in_array($variable, $this->getBaseVariables(), true)) {
            return true;
        }

        return in_array($variable, $this->getAttributeVariables(), true);
    }

    /** @return string[] */
    private function getBaseVariables(): array
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

        $context = [
            'name' => (string) $translation->getName(),
            'description' => (string) $translation->getDescription(),
            'shortDescription' => $shortDescription,
            'metaDescription' => (string) $translation->getMetaDescription(),
            'metaKeywords' => (string) $translation->getMetaKeywords(),
            'productId' => (string) $product->getId(),
            'code' => method_exists($product, 'getCode') ? (string) $product->getCode() : '',
            'productData' => '',
        ];

        if (method_exists($product, 'getAttributes')) {
            foreach ($product->getAttributes() as $attributeValue) {
                if (!method_exists($attributeValue, 'getAttribute')) {
                    $this->logger->warning('[AiAudit] Attribute value missing getAttribute() method', [
                        'class' => is_object($attributeValue) ? $attributeValue::class : gettype($attributeValue),
                    ]);
                    continue;
                }

                $attribute = $attributeValue->getAttribute();
                if ($attribute === null || !method_exists($attribute, 'getCode')) {
                    $this->logger->warning('[AiAudit] Attribute missing or code inaccessible', [
                        'attribute_class' => is_object($attribute) ? $attribute::class : gettype($attribute),
                    ]);
                    continue;
                }

                $locale = $translation->getLocale();
                if (method_exists($attributeValue, 'setCurrentLocale')) {
                    $attributeValue->setCurrentLocale($locale);
                }
                if (method_exists($attributeValue, 'setFallbackLocale')) {
                    $attributeValue->setFallbackLocale($locale);
                }

                $code = (string) $attribute->getCode();
                if ($code === '') {
                    $this->logger->debug('[AiAudit] Empty attribute code skipped');
                    continue;
                }

                $value = $this->stringifyAttributeValue($attributeValue->getValue());
                if ($value === '') {
                    $this->logger->debug('[AiAudit] Attribute value empty after locale assignment', [
                        'code' => $code,
                        'locale' => $locale,
                        'attribute_value_class' => $attributeValue::class,
                    ]);
                }
                $context[self::ATTRIBUTE_PREFIX . $code] = $value;
            }
        }

        return $context;
    }

    /** @return string[] */
    private function getAttributeVariables(): array
    {
        $attributes = $this->productAttributeRepository->findAll();

        $codes = array_filter(array_map(static function ($attribute): string {
            if (!is_object($attribute) || !method_exists($attribute, 'getCode')) {
                return '';
            }

            return (string) $attribute->getCode();
        }, $attributes));

        return array_map(
            static fn (string $code): string => self::ATTRIBUTE_PREFIX . $code,
            array_values(array_unique($codes)),
        );
    }

    private function stringifyAttributeValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_array($value)) {
            return implode(', ', array_map('strval', $value));
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }
}
