<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Support;

/**
 * Immutable, resolved connection settings for a single Zernio API call.
 *
 * Built by {@see \Spora\Plugins\Zernio\Tools\AbstractZernioTool::resolveConfig()}
 * from the layered tool settings (schema default → global → user → agent) with
 * a `ZERNIO_API_KEY` environment fallback, so the transport layer never has to
 * know how credentials were resolved.
 */
final readonly class ZernioConfig
{
    public function __construct(
        public string $apiKey,
        public string $baseUrl,
        public int $timeout,
    ) {}
}
