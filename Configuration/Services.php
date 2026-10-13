<?php

declare(strict_types=1);

/**
 * Conditional DI bindings — Feature 004 decision-cache.
 *
 * Activates the CachedDecisionRepository decorator + KVS-backed invalidator when:
 *  (a) moselwal/keyvalue-store is installed (interface exists), AND
 *  (b) extension config cacheEnabled=1 (default).
 *
 * Otherwise Services.yaml defaults apply: bare LapiDecisionRepository +
 * NullDecisionCacheInvalidator. Constitution null-adapter pattern.
 */

use Koh\CrowdsecBridge\Domain\Contract\DecisionCacheInvalidatorInterface;
use Koh\CrowdsecBridge\Domain\Contract\DecisionRepositoryInterface;
use Koh\CrowdsecBridge\Infrastructure\Cache\CacheBackendStateTracker;
use Koh\CrowdsecBridge\Infrastructure\Cache\CachedDecisionRepository;
use Koh\CrowdsecBridge\Infrastructure\Cache\CacheKeyBuilder;
use Koh\CrowdsecBridge\Infrastructure\Cache\KeyValueStoreDecisionCacheInvalidator;
use Koh\CrowdsecBridge\Infrastructure\Repository\LapiDecisionRepository;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Utility\GeneralUtility;

return static function (ContainerConfigurator $containerConfigurator, ContainerBuilder $containerBuilder): void {
    if (!interface_exists('Moselwal\\KeyValueStore\\KeyValueStoreInterface')) {
        return;
    }

    $cacheEnabled = false;
    $ttlSeconds = CachedDecisionRepository::TTL_DEFAULT;
    try {
        $extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
        $cacheEnabled = '1' === (string) $extensionConfiguration->get('crowdsec_bridge', 'cacheEnabled');
        $ttlRaw = (string) $extensionConfiguration->get('crowdsec_bridge', 'cacheTtl');
        if (ctype_digit($ttlRaw)) {
            $ttlSeconds = (int) $ttlRaw;
        }
    } catch (Throwable) {
        // Extension configuration not available yet (e.g. install phase) — keep defaults.
    }

    if (!$cacheEnabled) {
        return;
    }

    $services = $containerConfigurator->services()->defaults()->autowire()->autoconfigure();

    // Override the repository binding: callers of DecisionRepositoryInterface get the decorator.
    $services
        ->set(CachedDecisionRepository::class)
        ->args([
            '$inner' => service(LapiDecisionRepository::class),
            '$kvs' => service('Moselwal\\KeyValueStore\\KeyValueStoreInterface'),
            '$keyBuilder' => service(CacheKeyBuilder::class),
            '$tracker' => service(CacheBackendStateTracker::class),
            '$logger' => service('Psr\\Log\\LoggerInterface'),
            '$ttlSeconds' => $ttlSeconds,
        ]);

    $services
        ->set(DecisionRepositoryInterface::class)
        ->alias(DecisionRepositoryInterface::class, CachedDecisionRepository::class);

    $services
        ->set(DecisionCacheInvalidatorInterface::class)
        ->alias(DecisionCacheInvalidatorInterface::class, KeyValueStoreDecisionCacheInvalidator::class);
};
