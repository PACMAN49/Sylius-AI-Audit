<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\Controller\Admin;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PlanetRide\SyliusAiAuditPlugin\Settings\AiAuditPromptRenderer;
use PlanetRide\SyliusAiAuditPlugin\Settings\AiAuditPromptVariables;
use PlanetRide\SyliusAiAuditPlugin\Settings\AiAuditSettingsProvider;
use Sylius\Component\Product\Repository\ProductRepositoryInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class AiAuditController extends AbstractController
{
    private const MODEL = 'gpt-4.1-mini';

    public function __construct(
        private readonly ProductRepositoryInterface $productRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly EntityManagerInterface $entityManager,
        private readonly AiAuditSettingsProvider $settingsProvider,
        private readonly AiAuditPromptRenderer $promptRenderer,
        private readonly AiAuditPromptVariables $promptVariables,
    ) {
    }

    public function getStoredAuditAction(Request $request, int $id): JsonResponse
    {
        $product = $this->productRepository->find($id);

        if ($product === null) {
            return $this->json(['error' => 'Produit introuvable.'], 404);
        }

        $translation = $product->getTranslation($request->getLocale());

        $content = method_exists($translation, 'getAiAuditContent') ? $translation->getAiAuditContent() : null;
        $score = method_exists($translation, 'getAiAuditScore') ? $translation->getAiAuditScore() : null;
        $updatedAt = method_exists($translation, 'getAiAuditUpdatedAt') ? $translation->getAiAuditUpdatedAt() : null;

        if ($content === null && $score === null && $updatedAt === null) {
            $fallback = $this->fetchAuditFromDb($translation->getLocale(), (int) $product->getId());
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
        $product = $this->productRepository->find($id);

        if ($product === null) {
            return $this->json(['error' => 'Produit introuvable.'], 404);
        }

        $apiKey = $_ENV['OPENAI_API_KEY'] ?? $_SERVER['OPENAI_API_KEY'] ?? null;

        if ($apiKey === null || $apiKey === '') {
            return $this->json(['error' => 'OPENAI_API_KEY manquant.'], 500);
        }

        $locale = $request->getLocale();
        $translation = $product->getTranslation($locale);

        $name = (string) $translation->getName();
        $description = (string) $translation->getDescription();
        $shortDescription = method_exists($translation, 'getShortDescription') ? (string) $translation->getShortDescription() : '';
        $metaDescription = (string) $translation->getMetaDescription();
        $metaKeywords = (string) $translation->getMetaKeywords();

        $settings = $this->settingsProvider->getSettings();
        $systemPromptTemplate = $settings->getSystemPrompt() ?? '';

        $productData = implode("\n", [
            'Titre (Name) :',
            $name,
            '',
            'Description :',
            $description,
            '',
            'Résumé court :',
            $shortDescription,
            '',
            'Highlights :',
            '(non fournis ici)',
            '',
            'Meta description :',
            $metaDescription,
            '',
            'Meta keywords :',
            $metaKeywords,
            '',
            'Jour par jour :',
            '(non fourni ici)',
            '',
            'Inclusions / Non-inclusions / Infos importantes :',
            '(non fourni ici)',
        ]);

        $context = $this->promptVariables->buildContext($product, $translation);
        $context['productData'] = $productData;

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

        try {
            $response = $this->httpClient->request('POST', 'https://api.openai.com/v1/responses', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
                'json' => [
                    'model' => self::MODEL,
                    'input' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user', 'content' => $userPrompt],
                    ],
                    'temperature' => 0.2,
                    'max_output_tokens' => 5000,
                    'text' => ['verbosity' => 'medium'],
                ],
            ]);

            $statusCode = $response->getStatusCode();

            if ($statusCode < 200 || $statusCode >= 300) {
                return $this->json(['error' => 'Erreur API OpenAI (' . $statusCode . ').'], 502);
            }

            $data = $response->toArray(false);

            $outputText = $data['output_text'] ?? null;

            if (is_string($outputText) && trim($outputText) !== '') {
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
                            return $this->persistAndRespond($translation, $text);
                        }
                    }
                }
            }

            return $this->json(['error' => 'Réponse OpenAI invalide ou vide.'], 502);
        } catch (TransportExceptionInterface $exception) {
            return $this->json(['error' => 'Erreur réseau: ' . $exception->getMessage()], 502);
        }
    }

    private function persistAndRespond(object $translation, string $text): JsonResponse
    {
        $score = $this->extractScore($text);

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
            return null;
        }

        if (preg_match('/Score:\s*(\d{1,3})/i', $text, $matches) !== 1) {
            return null;
        }

        $score = (int) $matches[1];

        return max(0, min(100, $score));
    }

    private function persistAuditFallback(string $locale, int $productId, string $content, ?int $score): void
    {
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
