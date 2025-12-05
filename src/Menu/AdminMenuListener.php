<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\Menu;

use Sylius\Bundle\UiBundle\Menu\Event\MenuBuilderEvent;

final class AdminMenuListener
{
    public function addMenuItems(MenuBuilderEvent $event): void
    {
        $menu = $event->getMenu();

        $configuration = $menu->getChild('configuration');
        $parent = $configuration ?? $menu;

        $settings = $parent->addChild('planetride_ai_audit_settings', [
            'route' => 'planetride_sylius_ai_audit_admin_settings',
        ]);
        $settings->setLabel('AI Audit');
        $settings->setLabelAttribute('icon', 'cog');
    }
}
