<?php

declare(strict_types=1);

namespace PlanetRide\SyliusAiAuditPlugin\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    /**
     * @psalm-suppress UnusedVariable
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('planetride_sylius_ai_audit_plugin');
        $rootNode = $treeBuilder->getRootNode();

        return $treeBuilder;
    }
}
