<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\Controller\Admin;

use PlanetRide\SyliusAiAuditPlugin\Settings\AiAuditPromptValidator;
use PlanetRide\SyliusAiAuditPlugin\Settings\AiAuditPromptVariables;
use PlanetRide\SyliusAiAuditPlugin\Settings\AiAuditSettingsProvider;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\Extension\Core\Type\SubmitType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

final class AiAuditSettingsController extends AbstractController
{
    public function __construct(
        private readonly AiAuditSettingsProvider $settingsProvider,
        private readonly AiAuditPromptValidator $promptValidator,
        private readonly AiAuditPromptVariables $promptVariables,
    ) {
    }

    #[Route(path: '/admin/ai-audit/settings', name: 'planetride_sylius_ai_audit_admin_settings')]
    public function __invoke(Request $request): Response
    {
        $settings = $this->settingsProvider->getSettings();

        $form = $this->createFormBuilder([
            'systemPrompt' => $settings->getSystemPrompt(),
            'userPrompt' => $settings->getUserPrompt(),
        ])
            ->add('systemPrompt', TextareaType::class, [
                'label' => 'Prompt système',
                'required' => false,
                'attr' => ['rows' => 6],
                'help' => 'Contexte et consignes fixes envoyés à l’IA.',
            ])
            ->add('userPrompt', TextareaType::class, [
                'label' => 'Prompt utilisateur',
                'required' => false,
                'attr' => ['rows' => 8],
                'help' => 'Modèle de message utilisateur (le contenu produit sera injecté).',
            ])
            ->add('save', SubmitType::class, [
                'label' => 'Enregistrer',
                'attr' => ['class' => 'btn btn-primary'],
            ])
            ->getForm();

        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();

            $invalidSystem = $this->promptValidator->validate($data['systemPrompt'] ?? '');
            $invalidUser = $this->promptValidator->validate($data['userPrompt'] ?? '');

            if (!empty($invalidSystem) || !empty($invalidUser)) {
                $invalid = array_unique(array_merge($invalidSystem, $invalidUser));
                $form->addError(new FormError('Variables inconnues : ' . implode(', ', $invalid)));
            } else {
                $this->settingsProvider->update(
                    $data['systemPrompt'] ?? null,
                    $data['userPrompt'] ?? null,
                );

                $this->addFlash('success', 'Prompts Ai Audit mis à jour.');

                return $this->redirectToRoute('planetride_sylius_ai_audit_admin_settings');
            }
        }

        return $this->render('@SyliusAiAuditPlugin/admin/ai_audit/settings.html.twig', [
            'form' => $form->createView(),
            'updatedAt' => $settings->getUpdatedAt(),
            'allowedVariables' => $this->promptVariables->getAllowedVariables(),
        ]);
    }
}
