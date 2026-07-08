<?php

declare(strict_types=1);

namespace Spora\Plugins\Zernio\Support\Exceptions;

use RuntimeException;

/**
 * Thrown by {@see \Spora\Plugins\Zernio\Support\ZernioClient} for transport
 * failures and for any HTTP response with status >= 400. The status code is
 * preserved (0 for transport-level failures) so callers can distinguish auth
 * (401/403), rate limiting (429), and server errors when surfacing messages.
 */
final class ZernioApiException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $statusCode = 0,
    ) {
        parent::__construct($message);
    }
}
