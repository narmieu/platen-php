<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Config\PlatenConfig;

/**
 * Builds a {@see PlatenConfig} with sensible test defaults. Every setting has a named
 * parameter, so a test overrides only what it cares about, e.g.
 * TestConfig::create(apiKeys: 'k1,k2', maxRequestMb: 1).
 */
final class TestConfig
{
    public static function create(
        string $apiKeys = '',
        string $publicUrl = '',
        string $defaultEngine = 'weasyprint',
        string $blockedNetworks = '',
        string $dataDir = '/tmp/platen-test-data',
        string $chromiumUrl = 'http://127.0.0.1:3000',
        string $egressProxy = 'http://127.0.0.1:3128',
        bool $princeCompat = true,
        string $weasyprintBinary = 'weasyprint-platen',
        string $qpdfBinary = 'qpdf',
        float $syncTimeout = 60.0,
        float $asyncTimeout = 600.0,
        float $jsTimeout = 30.0,
        int $fetchTimeout = 10,
        bool $testWatermark = true,
        float $asyncRetentionHours = 24.0,
        float $logRetentionDays = 30.0,
        bool $blockPrivateNetworks = false,
        bool $allowFileUrls = false,
        int $maxRequestMb = 100,
    ): PlatenConfig {
        return new PlatenConfig(
            apiKeys: $apiKeys,
            publicUrl: $publicUrl,
            defaultEngine: $defaultEngine,
            blockedNetworks: $blockedNetworks,
            dataDir: $dataDir,
            chromiumUrl: $chromiumUrl,
            egressProxy: $egressProxy,
            princeCompat: $princeCompat,
            weasyprintBinary: $weasyprintBinary,
            qpdfBinary: $qpdfBinary,
            syncTimeout: $syncTimeout,
            asyncTimeout: $asyncTimeout,
            jsTimeout: $jsTimeout,
            fetchTimeout: $fetchTimeout,
            testWatermark: $testWatermark,
            asyncRetentionHours: $asyncRetentionHours,
            logRetentionDays: $logRetentionDays,
            blockPrivateNetworks: $blockPrivateNetworks,
            allowFileUrls: $allowFileUrls,
            maxRequestMb: $maxRequestMb,
        );
    }
}
