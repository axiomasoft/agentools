<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Conformance\Host;

require_once dirname(__DIR__, 3).'/examples/platform/edufy-host/bootstrap.php';

use AgenToolsPlatform\Core\Actions\Effects\{Request, Strategy};
use AgenToolsPlatform\Core\Common\{ActionId, CanonicalHasher, Ref, UtcInstant};
use AgenToolsPlatform\Examples\EdufyHost\{IntakeStub, SavedEvidence, SchoolApi, SchoolHost};
use AgenToolsPlatform\Laravel\Host\{Boundary, Envelope, ReportAdapter};
use AgenToolsPlatform\Laravel\Managed\Input;
use AgenToolsPlatform\Tests\JcsAdapter;

final class Fixture
{
    public CanonicalHasher $hasher;
    public SchoolHost $host;
    public Boundary $boundary;
    public IntakeStub $intake;
    public SavedEvidence $evidence;
    public SchoolApi $api;
    public Envelope $envelope;
    public ReportAdapter $reports;
    public function __construct()
    {
        $this->hasher = new CanonicalHasher(new JcsAdapter()); $this->host = new SchoolHost($this->hasher);
        $this->boundary = new Boundary($this->host, $this->host, $this->hasher);
        $this->intake = new IntakeStub($this->boundary, $this->hasher);
        $this->evidence = new SavedEvidence($this->host);
        $this->api = new SchoolApi($this->host, $this->boundary, $this->evidence);
        $this->envelope = $this->boundary->open(new Input('input-a'));
        $this->reports = $this->adapter($this->envelope);
    }
    public function adapter(Envelope $envelope): ReportAdapter
    {
        return new ReportAdapter($this->boundary, $envelope, $this->api, $this->evidence, $this->hasher, $this->host);
    }
    public function request(string $key = 'operation-1', string $fact = 'Synthetic approved fact', int $version = 1): Request
    {
        $tenant = $this->envelope->input->context->identity->tenant;
        $input = json_encode(['report_ref' => 'report-1', 'expected_revision' => $version, 'fact' => $fact], JSON_THROW_ON_ERROR);
        $hash = $this->hasher->hash($tenant, 'action-input', json_decode($input, false, 512, JSON_THROW_ON_ERROR));
        $ref = new Ref($tenant, 'owner:input:'.substr($hash->value, 8));
        $this->api->inputs[$tenant->value.':'.$ref->value] = $input;
        return new Request(new ActionId($tenant, 'action-1'), 'effect-1', $this->reports->namespace(), $key,
            $hash, $ref, $this->envelope->input->context, 1, Strategy::NativeKey);
    }
    public function approve(Request $request): void
    {
        $this->evidence->affirmative($this->envelope, $request, $this->envelope->input->context->identity->actorRef,
            $this->host->ref('authenticated-ui-event-1'), 'ui', new UtcInstant('2026-11-03T07:30:00Z'));
    }
}
