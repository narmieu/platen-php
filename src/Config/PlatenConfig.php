<?php

declare(strict_types=1);

namespace App\Config;

use App\Rendering\Engine;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Runtime settings, read from PLATEN_* environment variables. Defaults live in .env.
 */
final readonly class PlatenConfig
{
    /**
     * Keys accepted as the Basic auth user name or user_credentials. Empty means any key
     * (or none) is accepted.
     *
     * @var list<string>
     */
    public array $apiKeys;

    /** Base of download links, e.g. https://pdf.example.com. Null means the request's own URL. */
    public ?string $publicUrl;

    public Engine $defaultEngine;

    /** @var list<string> Extra CIDR ranges the renderers may not reach. */
    public array $blockedNetworks;

    public function __construct(
        #[Autowire(env: 'PLATEN_API_KEYS')]
        string $apiKeys,
        #[Autowire(env: 'PLATEN_PUBLIC_URL')]
        string $publicUrl,
        #[Autowire(env: 'PLATEN_DEFAULT_ENGINE')]
        string $defaultEngine,
        #[Autowire(env: 'PLATEN_BLOCKED_NETWORKS')]
        string $blockedNetworks,
        /** Where stored PDFs live. */
        #[Autowire(env: 'resolve:PLATEN_DATA_DIR')]
        public string $dataDir,
        /** Gotenberg, which runs Chromium for us. */
        #[Autowire(env: 'PLATEN_GOTENBERG_URL')]
        public string $gotenbergUrl,
        /** The egress proxy every renderer fetch goes through (see platen:egress-proxy). */
        #[Autowire(env: 'PLATEN_EGRESS_PROXY')]
        public string $egressProxy,
        #[Autowire(env: 'PLATEN_WEASYPRINT_BINARY')]
        public string $weasyprintBinary,
        /** weasyprint-platen-server, which keeps WeasyPrint warm; '' runs the command per document. */
        #[Autowire(env: 'PLATEN_WEASYPRINT_SERVER')]
        public string $weasyprintServer,
        /** Imitate Prince when a request names a Prince pipeline number. */
        #[Autowire(env: 'bool:default:platen_prince_compat_default:PLATEN_PRINCE_COMPAT')]
        public bool $princeCompat,
        #[Autowire(env: 'PLATEN_QPDF_BINARY')]
        public string $qpdfBinary,
        /** Seconds per document; DocRaptor's limits are 60 (sync) and 600 (async). */
        #[Autowire(env: 'float:PLATEN_SYNC_TIMEOUT')]
        public float $syncTimeout,
        #[Autowire(env: 'float:PLATEN_ASYNC_TIMEOUT')]
        public float $asyncTimeout,
        /** Seconds to wait for docraptorJavaScriptFinished() to return true. */
        #[Autowire(env: 'float:PLATEN_JS_TIMEOUT')]
        public float $jsTimeout,
        /** Seconds per asset request. */
        #[Autowire(env: 'int:PLATEN_FETCH_TIMEOUT')]
        public int $fetchTimeout,
        #[Autowire(env: 'bool:PLATEN_TEST_WATERMARK')]
        public bool $testWatermark,
        #[Autowire(env: 'float:PLATEN_ASYNC_RETENTION_HOURS')]
        public float $asyncRetentionHours,
        #[Autowire(env: 'float:PLATEN_LOG_RETENTION_DAYS')]
        public float $logRetentionDays,
        #[Autowire(env: 'bool:PLATEN_BLOCK_PRIVATE_NETWORKS')]
        public bool $blockPrivateNetworks,
        #[Autowire(env: 'bool:PLATEN_ALLOW_FILE_URLS')]
        public bool $allowFileUrls,
        #[Autowire(env: 'int:PLATEN_MAX_REQUEST_MB')]
        public int $maxRequestMb,
    ) {
        $this->apiKeys = self::splitList($apiKeys);
        $this->publicUrl = '' === trim($publicUrl) ? null : rtrim(trim($publicUrl), '/');
        $this->defaultEngine = Engine::tryFrom(strtolower(trim($defaultEngine)))
            ?? throw new \InvalidArgumentException('PLATEN_DEFAULT_ENGINE must be "weasyprint" or "chromium".');
        $this->blockedNetworks = self::splitList($blockedNetworks);
    }

    /**
     * The socket of the weasyprint-platen-server for one set of patch layers.
     */
    public function weasyprintSocket(bool $princeCompat): string
    {
        return $this->dataDir.'/run/'.($princeCompat ? 'weasyprint-prince.sock' : 'weasyprint.sock');
    }

    public function maxRequestBytes(): int
    {
        return $this->maxRequestMb * 1024 * 1024;
    }

    /** @return list<string> */
    private static function splitList(string $value): array
    {
        return array_values(array_filter(array_map(trim(...), explode(',', $value)), static fn (string $item): bool => '' !== $item));
    }
}
