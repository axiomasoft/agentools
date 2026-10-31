<?php

declare(strict_types=1);

namespace AgenToolsPlatform\ModelAdapter\Http;

final readonly class HttpRequest
{
    /** @param array<string, string> $headers */
    public function __construct(public string $url, private array $headers, public string $body)
    {
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null
            || parse_url($url, PHP_URL_PASS) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new \InvalidArgumentException('HTTP transport requires an HTTPS endpoint without embedded credentials');
        }
        foreach ($headers as $name => $value) {
            if (preg_match('/^[A-Za-z0-9-]+$/D', $name) !== 1 || preg_match('/[\r\n\x00]/', $value)) {
                throw new \InvalidArgumentException('Invalid HTTP header');
            }
        }
    }
    /** @return array<string, string> */
    public function headers(): array { return $this->headers; }
    /** @return array<string, string> */
    public function __debugInfo(): array { return ['url' => $this->url, 'headers' => '[redacted]', 'body' => '[redacted]']; }
}
