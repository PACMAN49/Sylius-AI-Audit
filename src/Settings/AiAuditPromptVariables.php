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
            'metadataTitle',
            'metadataDescription',
            'brand',
            'sku',
            'gtin8',
            'gtin13',
            'gtin14',
            'mpn',
            'isbn',
            'productId',
            'code',
            'productData',
        ];
    }

    /** @return array<string,string> */
    public function buildContext(ProductInterface $product, ProductTranslationInterface $translation, string $locale): array
    {
        $shortDescription = method_exists($translation, 'getShortDescription') ? (string) $translation->getShortDescription() : '';

        $this->assignSeoLocales($product, $locale);

        $context = [
            'name' => (string) $translation->getName(),
            'description' => (string) $translation->getDescription(),
            'shortDescription' => $shortDescription,
            'metaDescription' => (string) $translation->getMetaDescription(),
            'metaKeywords' => (string) $translation->getMetaKeywords(),
            'metadataTitle' => method_exists($product, 'getMetadataTitle') ? (string) ($product->getMetadataTitle() ?? '') : '',
            'metadataDescription' => method_exists($product, 'getMetadataDescription') ? (string) ($product->getMetadataDescription() ?? '') : '',
            'brand' => method_exists($product, 'getSEOBrand') ? (string) ($product->getSEOBrand() ?? '') : '',
            'sku' => $this->resolveSku($product),
            'gtin8' => method_exists($product, 'getSEOGtin8') ? (string) ($product->getSEOGtin8() ?? '') : '',
            'gtin13' => method_exists($product, 'getSEOGtin13') ? (string) ($product->getSEOGtin13() ?? '') : '',
            'gtin14' => method_exists($product, 'getSEOGtin14') ? (string) ($product->getSEOGtin14() ?? '') : '',
            'mpn' => method_exists($product, 'getSEOMpn') ? (string) ($product->getSEOMpn() ?? '') : '',
            'isbn' => method_exists($product, 'getSEOIsbn') ? (string) ($product->getSEOIsbn() ?? '') : '',
            'productId' => (string) $product->getId(),
            'code' => method_exists($product, 'getCode') ? (string) $product->getCode() : '',
            'productData' => '',
        ];

        if (method_exists($product, 'getAttributes')) {
            foreach ($product->getAttributesByLocale($locale, $locale) as $attributeValue) {
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
        if ($value instanceof \Traversable) {
            $value = iterator_to_array($value);
        }

        if (is_array($value)) {
            $parts = [];

            array_walk_recursive($value, function ($item) use (&$parts): void {
                $stringValue = $this->stringifyLeafValue($item);
                if ($stringValue !== '') {
                    $parts[] = $stringValue;
                }
            });

            return implode(PHP_EOL, $parts);
        }

        return $this->stringifyLeafValue($value);
    }

    private function stringifyLeafValue(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return '';
    }

    private function assignSeoLocales(ProductInterface $product, string $locale): void
    {
        if (method_exists($product, 'setReferenceableLocale')) {
            $product->setReferenceableLocale($locale);
        }
        if (method_exists($product, 'setReferenceableFallbackLocale')) {
            $product->setReferenceableFallbackLocale($locale);
        }

        if (!method_exists($product, 'getReferenceableContent')) {
            return;
        }

        $seoContent = $product->getReferenceableContent();
        if (method_exists($seoContent, 'setCurrentLocale')) {
            $seoContent->setCurrentLocale($locale);
        }
        if (method_exists($seoContent, 'setFallbackLocale')) {
            $seoContent->setFallbackLocale($locale);
        }
    }

    private function resolveSku(ProductInterface $product): string
    {
        if (method_exists($product, 'getSEOSku')) {
            $seoSku = (string) ($product->getSEOSku() ?? '');
            if ($seoSku !== '') {
                return $seoSku;
            }
        }

        return method_exists($product, 'getCode') ? (string) $product->getCode() : '';
    }
}
