<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Chatom;

use AgenTools\Chatom\Bridge\BridgeKeys;
use AgenTools\Chatom\Data\IncomingInput;
use AgenTools\Chatom\Data\SourceReference;
use AgenTools\Chatom\Nodes\Shape;
use AgenToolsPlatform\Core\Common\ContractViolation;
use AgenToolsPlatform\Core\Common\ErrorCode;
use AgenToolsPlatform\Core\Common\Ref;
use AgenToolsPlatform\Core\Context\CompiledContext;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Postgres\Delivery\DeliveryRepository;
use AgenToolsPlatform\Postgres\Payloads\DerivedPayloadRepository;
use AgenToolsPlatform\Postgres\Runtime\Connection;
use AgenToolsPlatform\Postgres\Runtime\RepositoryContext;
use Chatom\Channels\Data\ChannelId;
use Chatom\Spaces\Data\SpaceId;
use PDO;

/** P10 producer bindings only. No cleanup authority, coverage claims for inactive stores, or grant cache. */
final class TextPayloadLineage
{
    /** @var array<string,array{dependency:string,sources:array<string,int>}> */
    private array $expected = [];

    public function __construct(private readonly RepositoryContext $environment,
        private readonly DerivedPayloadRepository $derived, private readonly DeliveryRepository $deliveries,
        private readonly NativeHistory $history, private readonly SpaceId $space, private readonly ChannelId $channel) {}

    public function input(ExecutionContext $context, Ref $key, IncomingInput $input): void
    {
        if ($input->space->value !== $this->space->value || $input->source->channel !== $this->channel->value) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        $this->expect($context, $key, 'temporary-payloads', [$this->source($input->source->id) => $input->revision]);
    }

    public function compiled(ExecutionContext $context, Ref $key, CompiledContext $compiled): void
    {
        $compiled->assertIntegrity($context, $this->environment->hasher);
        $manifest = json_decode($compiled->manifestJson, true, 64, JSON_THROW_ON_ERROR);
        $sections = is_array($manifest) ? ($manifest['sections'] ?? null) : null;

        if (! is_array($sections) || ! array_is_list($sections)) {
            throw new ContractViolation(ErrorCode::IntegrityConflict);
        }
        $sources = [];
        foreach ($sections as $section) {
            if (! is_array($section) || ! in_array($section['kind'] ?? null, ['input', 'recent'], true)) {
                continue;
            }
            $ref = $section['source_ref'] ?? null;
            $version = $section['source_version'] ?? null;

            if (! is_string($ref) || ! str_starts_with($ref, 'chatom:message:')
                || ! is_string($version) || preg_match('/\A[1-9][0-9]*\z/D', $version) !== 1) {
                throw new ContractViolation(ErrorCode::IntegrityConflict);
            }
            $sources[$this->source(substr($ref, strlen('chatom:message:')))] = (int) $version;
        }
        $this->expect($context, $key, 'context', $sources);
    }

    /** Derive downstream lineage from committed producer bindings, never from a guessed conversation range. */
    public function inherit(ExecutionContext $context, Ref $parent, Ref $key, string $dependency): void
    {
        $sources = [];
        foreach ($this->rows($context, $parent) as $row) {
            $sources[Connection::text($row, 'source_ref')] = Connection::number($row, 'source_revision');
        }
        $this->expect($context, $key, $dependency, $sources);
    }

    /** The payload owner invokes this only after its metadata commits and before encrypted bytes are written. */
    public function registered(ExecutionContext $context, Ref $key): void
    {
        $binding = $this->expected[$key->value] ?? throw new ContractViolation(ErrorCode::PolicyConflict);
        $this->assertSources($context, $binding['sources']);
        foreach ($binding['sources'] as $source => $revision) {
            $this->derived->bind($context, new Ref($key->tenant, $source), $revision, $binding['dependency'], $key);
        }
        $rows = $this->rows($context, $key);

        if (count($rows) !== count($binding['sources'])) {
            throw new ContractViolation(ErrorCode::IntegrityConflict);
        }
        foreach ($rows as $row) {
            if (Connection::text($row, 'dependency') !== $binding['dependency']
                || ($binding['sources'][Connection::text($row, 'source_ref')] ?? null) !== Connection::number($row, 'source_revision')) {
                throw new ContractViolation(ErrorCode::IntegrityConflict);
            }
        }
    }

    /** Fresh-process reads require actual registered lineage and every current source version. */
    public function assertReadable(ExecutionContext $context, Ref $key): void
    {
        $rows = $this->rows($context, $key);
        $sources = [];
        foreach ($rows as $row) {
            $sources[Connection::text($row, 'source_ref')] = Connection::number($row, 'source_revision');
        }
        $this->assertSources($context, $sources);
    }

    /** @param array<string,int> $sources */
    private function expect(ExecutionContext $context, Ref $key, string $dependency, array $sources): void
    {
        $context->session->tenant->assertSame($key->tenant);

        if ($sources === []) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        ksort($sources);
        $binding = ['dependency' => $dependency, 'sources' => $sources];

        if (isset($this->expected[$key->value]) && $this->expected[$key->value] !== $binding) {
            throw new ContractViolation(ErrorCode::IntegrityConflict);
        }
        foreach ($sources as $source => $revision) {
            $fence = $this->environment->db->row('SELECT id FROM '.$this->environment->db->table('source_fences').'
                WHERE tenant_id=:tenant AND id=:id', ['tenant' => $key->tenant->value, 'id' => $source]);

            if ($fence === null) {
                $this->deliveries->openSource($context, new Ref($key->tenant, $source), $revision);
            }
        }
        $this->expected[$key->value] = $binding;
    }

    /** @param array<string,int> $sources */
    private function assertSources(ExecutionContext $context, array $sources): void
    {
        if ($sources === []) {
            throw new ContractViolation(ErrorCode::AccessDenied);
        }
        $manifest = $this->history->manifest($context, new Ref($context->session->tenant, $context->session->value), 9007199254740991);

        if (! $manifest->complete) {
            throw new ContractViolation(ErrorCode::CapabilityUnavailable);
        }
        $current = [];
        foreach ($manifest->messages as $message) {
            $current[$this->source(substr($message->ref->value, strlen('chatom:message:')))] = $message->version;
        }
        foreach ($sources as $source => $revision) {
            if ($revision < 1 || ($current[$source] ?? null) !== (string) $revision) {
                throw new ContractViolation(ErrorCode::StaleContext);
            }
            $fence = $this->environment->db->row('SELECT closed,watermark FROM '.$this->environment->db->table('source_fences').'
                WHERE tenant_id=:tenant AND id=:id', ['tenant' => $context->session->tenant->value, 'id' => $source]);

            if ($fence !== null && ($fence['closed'] !== false || Connection::number($fence, 'watermark') !== $revision)) {
                throw new ContractViolation(ErrorCode::Cancelled);
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function rows(ExecutionContext $context, Ref $key): array
    {
        $context->session->tenant->assertSame($key->tenant);
        $statement = $this->environment->db->execute('SELECT source_ref,source_revision,dependency FROM '.$this->environment->db->table('derived_payloads').'
            WHERE tenant_id=:tenant AND session_id=:session AND payload_id=:payload ORDER BY source_ref,dependency',
            ['tenant' => $key->tenant->value, 'session' => $context->session->value, 'payload' => $key->value]);
        $rows = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $rows[] = Shape::record($row);
        }

        return $rows;
    }

    private function source(string $message): string
    {
        $source = SourceReference::fromArray(['owner' => 'chatom', 'id' => $message, 'channel_id' => $this->channel->value]);

        return 'chatom:source:'.BridgeKeys::hash(['space' => $this->space->value, 'source' => $source->toArray()]);
    }
}
