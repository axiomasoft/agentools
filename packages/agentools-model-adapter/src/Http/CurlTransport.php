<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Http;

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\ModelAdapter\IOControl;

/** Native cURL multi pump: incremental bytes, bounded waits, verified TLS, no redirects/retries. */
final readonly class CurlTransport implements HttpTransport
{
    /** @var non-empty-string|null */
    private ?string $caFile;
    public function __construct(?string $caFile = null, private int $maxBytes = 8388608)
    {
        if ($caFile === '') { throw new \InvalidArgumentException('CA file must not be empty'); }
        $this->caFile = $caFile;
        if ($maxBytes < 1) { throw new \InvalidArgumentException('A response byte limit is required'); }
    }

    public function exchange(HttpRequest $request, IOControl $control): iterable
    {
        $control->check();
        CurlRuntime::assertCancellable(curl_version());
        $handle = curl_init($request->url);
        if ($handle === false) { throw new TransportFailure(ErrorCode::DeploymentUnavailable, false); }
        $multi = curl_multi_init();
        $sent = false;
        $added = false;
        $queue = [];
        $status = 0;
        $requestId = null;
        $received = 0;
        $overflow = false;
        try {
            $headers = [];
            foreach ($request->headers() as $name => $value) { $headers[] = $name . ': ' . $value; }
            $options = [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $request->body, CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_PROXY => '', CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_CONNECTTIMEOUT_MS => min(10000, $control->remainingMilliseconds()),
                CURLOPT_TIMEOUT_MS => $control->remainingMilliseconds(), CURLOPT_NOSIGNAL => true,
                CURLOPT_HEADERFUNCTION => static function (\CurlHandle $unused, string $line) use (&$queue, &$status, &$requestId): int {
                    if (preg_match('/^HTTP\/\S+ (\d{3})/', $line, $match)) { $status = (int) $match[1]; $requestId = null; }
                    elseif (str_starts_with(strtolower($line), 'request-id:')) { $requestId = trim(substr($line, 11)); }
                    elseif (trim($line) === '' && $status >= 200) { $queue[] = HttpEvent::headers($status, $requestId); }
                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function (\CurlHandle $unused, string $bytes) use (&$queue, &$received, &$overflow): int {
                    $received += strlen($bytes);
                    if ($received > $this->maxBytes) { $overflow = true; return 0; }
                    $queue[] = HttpEvent::body($bytes);
                    return strlen($bytes);
                },
            ];
            if ($this->caFile !== null) { $options[CURLOPT_CAINFO] = $this->caFile; }
            if (!curl_setopt_array($handle, $options) || curl_multi_add_handle($multi, $handle) !== CURLM_OK) {
                throw new TransportFailure(ErrorCode::DeploymentUnavailable, false);
            }
            $added = true;
            do {
                $control->check();
                $sent = true;
                $code = curl_multi_exec($multi, $running);
                if ($code !== CURLM_OK) { throw new TransportFailure(ErrorCode::InvocationUnknown, true); }
                $pending = $queue;
                $queue = [];
                foreach ($pending as $event) { $control->check(); yield $event; }
                if ($overflow) { throw new TransportFailure(ErrorCode::InvocationUnknown, true); }
                if ($running > 0) { curl_multi_select($multi, 0.05); }
            } while ($running > 0);
            while (($info = curl_multi_info_read($multi)) !== false) {
                if ($info['result'] !== CURLE_OK) {
                    throw new TransportFailure($info['result'] === CURLE_OPERATION_TIMEDOUT ? ErrorCode::ProviderTimeout : ErrorCode::InvocationUnknown, $sent);
                }
            }
        } catch (ContractViolation $error) {
            throw new TransportFailure($error->errorCode, $sent);
        } finally {
            if ($added) { curl_multi_remove_handle($multi, $handle); }
            curl_close($handle);
            curl_multi_close($multi);
        }
    }
}
