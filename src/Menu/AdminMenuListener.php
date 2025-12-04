<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function addMenuItems(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        // Ajout sous le menu principal (adapter si besoin : catalog/configuration/sales)
        $reports = $menu->addChild('planetride_ai_audit', [
            'route' => 'planetride_sylius_ai_audit_static_welcome', // adapte à ta route
        ]);
        $reports->setLabel('AI Audit');
        $reports->setLabelAttribute('icon', 'chart bar'); // optionnel, icône Semantic UI
    }
}
