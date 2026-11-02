<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Retrieval;

use AgenToolsPlatform\Knowledge\{EmbeddingSpace, Integrity, Scope};

/** Authorization metadata comes from the trusted host, never query text/model claims. */
final readonly class Query
{
    public string $normalized;
    public string $queryHash;

    /** @param non-empty-list<ReleasePin> $releases */
    public function __construct(public Scope $scope, public string $runRef, string $text,
        public array $releases, public string $locale, public ?string $program = null,
        public ?EmbeddingSpace $space = null, public int $maxFragments = 3,
        public int $maxBytes = 2048, public int $fragmentBytes = 512)
    {
        Integrity::text($runRef); Integrity::text($text); Integrity::text($locale);
        if ($program !== null) { Integrity::text($program); }
        if (strlen($text) > 4096 || $releases === [] || !array_is_list($releases) || count($releases) > 64
            || $maxFragments < 1 || $maxFragments > 8 || $maxBytes < 32 || $maxBytes > 8192
            || $fragmentBytes < 32 || $fragmentBytes > 1024 || $fragmentBytes > $maxBytes) {
            throw new \InvalidArgumentException('Bounded query, releases and evidence limits required');
        }
        $seen = [];
        foreach ($releases as $pin) {
            $identity = Integrity::digest([$pin->material->tenant, $pin->corpus, $pin->id]);
            if (isset($seen[$identity])) { throw new \InvalidArgumentException('Duplicate release identity'); }
            $seen[$identity] = true;
        }
        // Versioned normalization: collapse Unicode whitespace; ASCII case-fold only.
        $this->normalized = strtolower(trim(preg_replace('/\s+/u', ' ', $text) ?? ''));
        Integrity::text($this->normalized);
        $this->queryHash = hash('sha256', $this->normalized);
    }

    public function cacheKey(): string
    {
        return Integrity::digest(['retrieval/v1', $this->scope, $this->queryHash, $this->releases,
            $this->locale, $this->program, $this->space, $this->maxFragments, $this->maxBytes, $this->fragmentBytes]);
    }

    public function binding(): string { return Integrity::digest([$this->cacheKey(), $this->runRef]); }
}
