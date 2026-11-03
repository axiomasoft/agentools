<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Host;

use AgenToolsPlatform\Core\Common\Ref;

/** Host-owned current snapshot metadata. No persistent domain facts live here. */
final readonly class ContextState
{
    /**
     * @param array<string, string> $policyVersions
     * @param array<array-key, string> $capability Validated as a unique nonempty list.
     */
    public function __construct(public Ref $snapshot, public int $revision, public string $locale,
        public Ref $retentionPolicy, public Ref $residencyPolicy, public array $policyVersions,
        public array $capability, public ?Ref $educationSession = null, public ?Ref $realtimeSession = null,
        public ?string $traceparent = null)
    {
        foreach ([$retentionPolicy, $residencyPolicy, $educationSession, $realtimeSession] as $ref) {
            if ($ref !== null) { $snapshot->tenant->assertSame($ref->tenant); }
        }
        if ($revision < 1 || $revision > 9007199254740991
            || preg_match('/\A[a-zA-Z]{2,8}(?:-[a-zA-Z0-9]{1,8})*\z/D', $locale) !== 1
            || $policyVersions === [] || !array_is_list($capability) || $capability === []
            || count($capability) !== count(array_unique($capability))
            || ($traceparent !== null && (preg_match('/\A00-[a-f0-9]{32}-[a-f0-9]{16}-[a-f0-9]{2}\z/D', $traceparent) !== 1
                || substr($traceparent, 3, 32) === str_repeat('0', 32) || substr($traceparent, 36, 16) === str_repeat('0', 16)))) {
            throw new \InvalidArgumentException('Explicit bounded host context metadata required');
        }
        foreach ($policyVersions as $name => $version) { self::name($name); self::name($version); }
        foreach ($capability as $name) { self::name($name); }
    }

    private static function name(string $name): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._:-]{0,127}\z/D', $name) !== 1) {
            throw new \InvalidArgumentException('Explicit policy/capability name required');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['context' => ['snapshot_ref' => $this->snapshot->toArray(), 'revision' => $this->revision],
            'locale' => $this->locale, 'retention_policy_ref' => $this->retentionPolicy->toArray(),
            'residency_policy_ref' => $this->residencyPolicy->toArray(), 'policy_versions' => $this->policyVersions,
            'capability' => $this->capability, 'education_session_ref' => $this->educationSession?->toArray(),
            'realtime_session_ref' => $this->realtimeSession?->toArray(), 'traceparent' => $this->traceparent];
    }
}
