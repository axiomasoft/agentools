<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Http;

use AgenToolsPlatform\Core\Common\ErrorCode;

/** Synchronous DNS can block a multi pump outside cancellation/deadline checks. */
final class CurlRuntime
{
    /** @param array<array-key, mixed>|false $version */
    public static function assertCancellable(array|false $version): void
    {
        $features = is_array($version) ? ($version['features'] ?? null) : null;
        if (!is_int($features) || ($features & CURL_VERSION_ASYNCHDNS) === 0) {
            throw new TransportFailure(ErrorCode::DeploymentUnavailable, false);
        }
    }
}
