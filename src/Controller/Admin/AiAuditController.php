<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\Controller\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PlanetRide\SyliusAiAuditPlugin\Settings\AiAuditPromptRenderer;
use PlanetRide\SyliusAiAuditPlugin\Settings\AiAuditPromptVariables;
use PlanetRide\SyliusAiAuditPlugin\Settings\AiAuditSettingsProvider;
use Psr\Log\LoggerInterface;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiAuditController extends AbstractController
{
    private const MODEL = 'gpt-5-mini';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly AiAuditSettingsProvider $settingsProvider,
        private readonly AiAuditPromptRenderer $promptRenderer,
        private readonly AiAuditPromptVariables $promptVariables,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function getStoredAuditAction(Request $request, int $id): JsonResponse
    {
        $auditLocale = (string) $request->query->get('auditLocale', $request->getLocale());

        $this->logger->info('Fetching stored AI audit', [
            'productId' => $id,
            'auditLocale' => $auditLocale,
        ]);

        $product = $this->productRepository->find($id);

        if ($product === null) {
            $this->logger->warning('Product not found when fetching stored audit', [
                'productId' => $id,
            ]);

            return $this->json(['error' => 'Produit introuvable.'], 404);
        }

        $hasTranslation = method_exists($product, 'getTranslations') && $product->getTranslations()->containsKey($auditLocale);

        if (!$hasTranslation) {
            $this->logger->warning('Translation not found for requested audit locale', [
                'productId' => $id,
                'auditLocale' => $auditLocale,
            ]);

            return $this->json(['error' => sprintf('Traduction introuvable pour la locale %s.', $auditLocale)], 404);
        }

        $translation = $product->getTranslation($auditLocale);

        $content = method_exists($translation, 'getAiAuditContent') ? $translation->getAiAuditContent() : null;
        $score = method_exists($translation, 'getAiAuditScore') ? $translation->getAiAuditScore() : null;
        $updatedAt = method_exists($translation, 'getAiAuditUpdatedAt') ? $translation->getAiAuditUpdatedAt() : null;

        if ($content === null && $score === null && $updatedAt === null) {
            $this->logger->debug('No stored audit on translation, checking DB fallback', [
                'productId' => $product->getId(),
                'auditLocale' => $auditLocale,
            ]);

            $fallback = $this->fetchAuditFromDb($auditLocale, (int) $product->getId());
            $content = $fallback['content'];
            $score = $fallback['score'];
            $updatedAt = $fallback['updated_at'];
        }

        return $this->json([
            'retour' => $content,
            'score' => $score,
            'updatedAt' => $updatedAt?->format(DATE_ATOM),
        ]);
    }

    public function aiAuditAction(Request $request, int $id): JsonResponse
    {
        $auditLocale = (string) $request->query->get('auditLocale', $request->getLocale());

        $this->logger->info('AI audit requested', [
            'productId' => $id,
            'auditLocale' => $auditLocale,
        ]);

        $product = $this->productRepository->find($id);

        if ($product === null) {
            $this->logger->warning('Product not found for AI audit', ['productId' => $id]);
            return $this->json(['error' => 'Produit introuvable.'], 404);
        }

        $apiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? null;

        if ($apiKey === null || $apiKey === '') {
            $this->logger->error('OPENAI_API_KEY missing when triggering AI audit', [
                'productId' => $id,
            ]);
            return $this->json(['error' => 'OPENAI_API_KEY manquant.'], 500);
        }

        $hasTranslation = method_exists($product, 'getTranslations') && $product->getTranslations()->containsKey($auditLocale);

        if (!$hasTranslation) {
            $this->logger->warning('Translation not found for requested audit locale', [
                'productId' => $id,
                'auditLocale' => $auditLocale,
            ]);

            return $this->json(['error' => sprintf('Traduction introuvable pour la locale %s.', $auditLocale)], 404);
        }

        $translation = $product->getTranslation($auditLocale);

        $name = (string) $translation->getName();
        $description = (string) $translation->getDescription();
        $shortDescription = method_exists($translation, 'getShortDescription') ? (string) $translation->getShortDescription() : '';
        $metaDescription = (string) $translation->getMetaDescription();
        $metaKeywords = (string) $translation->getMetaKeywords();

        $settings = $this->settingsProvider->getSettings();
        $systemPromptTemplate = $settings->getSystemPrompt() ?? '';

        $productData = $settings->getUserPrompt();

        $context = $this->promptVariables->buildContext($product, $translation,$auditLocale);
        $context['productData'] = $productData;
        $attributeContext = array_filter(
            $context,
            static fn (string $key): bool => str_starts_with($key, 'attribute_'),
            ARRAY_FILTER_USE_KEY,
        );
        $this->logger->debug('[AiAudit] Attribute variables prepared for prompt', [
            'productId' => $id,
            'locale' => $auditLocale,
            'attribute_count' => count($attributeContext),
            'attributes_preview' => array_map(
                static fn (string $value): string => mb_substr($value, 0, 80),
                $attributeContext,
            ),
        ]);

        $userPromptTemplate = $settings->getUserPrompt() ?: implode("\n", [
            'Voici les contenus de la fiche produit à auditer :',
            '',
            $productData,
            '',
            'Calcule la note sur 100 selon les critères décrits dans le message système,',
            'puis liste tous les problèmes concrets à corriger (même mineurs) dans le bloc "Audit:".',
        ]);

        $systemPrompt = $this->promptRenderer->render($systemPromptTemplate, $context);
        $userPrompt = $this->promptRenderer->render($userPromptTemplate, $context);
        $inputPayload = [
            [
                'role' => 'system',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $systemPrompt,
                    ],
                ],
            ],
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_text',
                        'text' => $userPrompt,
                    ],
                ],
            ],
        ];

        $this->logger->info('Starting AI audit call to OpenAI', [
            'productId' => $id,
            'auditLocale' => $auditLocale,
            'model' => self::MODEL,
            'systemPromptLength' => strlen($systemPrompt),
            'systemPromptVariables' => $this->promptRenderer->extractVariables($systemPromptTemplate),
            'userPromptLength' => strlen($userPrompt),
            'userPromptVariables' => $this->promptRenderer->extractVariables($userPromptTemplate),

        ]);

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
                'timeout' => 180,       // attendre plus longtemps pour une réponse
                'max_duration' => 200,    // pas de limite de durée totale
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => self::MODEL,
                    'input' => $inputPayload,
                    'max_output_tokens' => 5000,
                ],
            ]);

            $statusCode = $response->getStatusCode();

            $this->logger->info('OpenAI response received', [
                'statusCode' => $statusCode,
                'productId' => $id,
            ]);

            if ($statusCode < 200 || $statusCode >= 300) {
                $responseBody = $response->getContent(false);
                $this->logger->error('OpenAI API returned non-success status', [
                    'statusCode' => $statusCode,
                    'productId' => $id,
                    'responseBody' => mb_substr($responseBody, 0, 800),
                ]);
                return $this->json(['error' => 'Erreur API OpenAI (' . $statusCode . ').'], 502);
            }

            $data = $response->toArray(false);

            $outputText = $data['output_text'] ?? null;

            if (is_string($outputText) && trim($outputText) !== '') {
                $this->logger->debug('OpenAI returned output_text', [
                    'productId' => $id,
                    'textLength' => strlen($outputText),
                ]);
                return $this->persistAndRespond($translation, $outputText);
            }

            if (isset($data['output']) && is_array($data['output'])) {
                foreach ($data['output'] as $chunk) {
                    if (($chunk['type'] ?? null) === 'message' && isset($chunk['content']) && is_array($chunk['content'])) {
                        $textParts = array_map(static fn (array $content) => $content['text'] ?? '', array_filter(
                            $chunk['content'],
                            static fn (array $content) => ($content['type'] ?? null) === 'output_text' || ($content['type'] ?? null) === 'text',
                        ));

                        $text = trim(implode("\n", $textParts));

                        if ($text !== '') {
                            $this->logger->debug('OpenAI returned text chunks', [
                                'productId' => $id,
                                'textLength' => strlen($text),
                            ]);
                            return $this->persistAndRespond($translation, $text);
                        }
                    }
                }
            }

            $this->logger->error('OpenAI response had no usable text', [
                'productId' => $id,
            ]);

            return $this->json(['error' => 'Reponse OpenAI invalide ou vide.'], 502);
        } catch (TransportExceptionInterface $exception) {
            $this->logger->error('Network error while calling OpenAI', [
                'productId' => $id,
                'message' => $exception->getMessage(),
            ]);
            return $this->json(['error' => 'Erreur reseau: ' . $exception->getMessage()], 502);
        }
    }

    private function persistAndRespond(object $translation, string $text): JsonResponse
    {
        $score = $this->extractScore($text);
        $this->logger->info('Persisting AI audit result', [
            'productId' => $translation->getTranslatable()?->getId(),
            'locale' => $translation->getLocale(),
            'score' => $score,
            'textLength' => strlen($text),
        ]);

        if (method_exists($translation, 'setAiAuditContent')) {
            $translation->setAiAuditContent($text);
        }

        if (method_exists($translation, 'setAiAuditScore')) {
            $translation->setAiAuditScore($score);
        }

        if (method_exists($translation, 'setAiAuditUpdatedAt')) {
            $translation->setAiAuditUpdatedAt(new \DateTimeImmutable());
        }

        if (
            method_exists($translation, 'setAiAuditContent') &&
            method_exists($translation, 'setAiAuditScore') &&
            method_exists($translation, 'setAiAuditUpdatedAt')
        ) {
            $this->entityManager->flush();
        } else {
            $this->logger->warning('Translation setters missing, using fallback persistence', [
                'productId' => $translation->getTranslatable()?->getId(),
                'locale' => $translation->getLocale(),
            ]);
            $this->persistAuditFallback(
                $translation->getLocale(),
                (int) $translation->getTranslatable()?->getId(),
                $text,
                $score,
            );
        }

        return $this->json([
            'retour' => $text,
            'score' => $score,
        ]);
    }

    private function extractScore(string $text): ?int
    {
        if ($text === '') {
            $this->logger->debug('Empty text received for score extraction');
            return null;
        }

        if (preg_match('/Score:\s*(\d{1,3})/i', $text, $matches) !== 1) {
            $this->logger->debug('No score found in audit text');
            return null;
        }

        $score = (int) $matches[1];
        $this->logger->debug('Score extracted from audit text', ['score' => $score]);

        return max(0, min(100, $score));
    }

    private function persistAuditFallback(string $locale, int $productId, string $content, ?int $score): void
    {
        $this->logger->info('Persisting audit via DB fallback', [
            'productId' => $productId,
            'locale' => $locale,
            'score' => $score,
            'contentLength' => strlen($content),
        ]);

        /** @var Connection $connection */
        $connection = $this->entityManager->getConnection();
        $connection->executeStatement(
            'UPDATE sylius_product_translation
                 SET ai_audit_content = :content,
                     ai_audit_score = :score,
                     ai_audit_updated_at = :updatedAt
               WHERE translatable_id = :productId AND locale = :locale',
            [
                'content' => $content,
                'score' => $score,
                'updatedAt' => (new \DateTimeImmutable())->format('Y-m-d H:i:s'),
                'productId' => $productId,
                'locale' => $locale,
            ],
        );
    }

    private function fetchAuditFromDb(string $locale, int $productId): array
    {
        $this->logger->debug('Fetching audit from DB fallback', [
            'productId' => $productId,
            'locale' => $locale,
        ]);

        /** @var Connection $connection */
        $connection = $this->entityManager->getConnection();
        $row = $connection->fetchAssociative(
            'SELECT ai_audit_content, ai_audit_score, ai_audit_updated_at
               FROM sylius_product_translation
              WHERE translatable_id = :productId AND locale = :locale',
            ['productId' => $productId, 'locale' => $locale],
        );

        return [
            'content' => $row['ai_audit_content'] ?? null,
            'score' => isset($row['ai_audit_score']) ? (int) $row['ai_audit_score'] : null,
            'updated_at' => isset($row['ai_audit_updated_at']) && $row['ai_audit_updated_at'] !== null
                ? new \DateTimeImmutable($row['ai_audit_updated_at'])
                : null,
        ];
    }
}
