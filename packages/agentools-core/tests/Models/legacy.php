<?php

declare(strict_types=1);

require_once __DIR__ . '/LegacyFixtureMapper.php';

use AgenTools\Contracts\LlmPort;
use AgenTools\Platform\Models\{LegacyLlmPortTransport, ModelTransportLlmPort};
use AgenTools\Reference\Adapters\ScriptedLlmPort;
use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\Tests\Models\{ModelFixtures as F, LegacyFixtureMapper};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode};
use AgenToolsPlatform\Core\Models\{Deployment, ModelRequest, VisionOption, VisionDetail};

return [
    'CM-030' => static function (): void {
        $fixture = F::fixture('CM-030');
        if (!is_array($fixture['legacy_exchanges'])) { throw new RuntimeException('Missing legacy fixture'); }
        foreach ($fixture['legacy_exchanges'] as $exchange) {
            if (!is_array($exchange) || !is_array($exchange['input']) || !is_array($exchange['output'])) { throw new RuntimeException('Invalid legacy exchange'); }
            /** @var array<string, mixed> $input */ $input = $exchange['input'];
            /** @var array<string, mixed> $expected */ $expected = $exchange['output'];
            $mapper = new LegacyFixtureMapper($input);
            $port = new class ($expected) implements LlmPort {
                public int $calls = 0;
                /** @var array<string, mixed>|null */ public ?array $received = null;
                /** @param array<string, mixed> $expected */
                public function __construct(private array $expected) {}
                public function respond(array $input): array { ++$this->calls; $this->received = $input; return $this->expected; }
            };
            $transport = new LegacyLlmPortTransport($port, $mapper, F::now(...));
            $bridge = new ModelTransportLlmPort($transport, F::deployment(), $mapper);
            H::check($bridge->respond($input) === $expected && $port->received === $input && $port->calls === 1);
        }
        $input = ['fixture' => 'simple_tool_call'];
        $mapper = new LegacyFixtureMapper($input);
        $scripted = new ScriptedLlmPort();
        $transport = new LegacyLlmPortTransport($scripted, $mapper, F::now(...));
        H::check((new ModelTransportLlmPort($transport, F::deployment(), $mapper))->respond($input) === $scripted->respond($input));
        $response = $transport->generate(F::request(), F::deployment());
        H::check($response->proposals[0]->providerProposalRef === 'legacy:invocation-1:0' && $response->actualDeployment === null && $response->usage->outputTokens === null);
    },
    'models.legacy-exceptions-preserved' => static function (): void {
        $exception = new RuntimeException('legacy failure');
        $port = new class ($exception) implements LlmPort {
            public function __construct(private RuntimeException $exception) {}
            public function respond(array $input): array { throw $this->exception; }
        };
        $mapper = new LegacyFixtureMapper([]);
        $bridge = new ModelTransportLlmPort(new LegacyLlmPortTransport($port, $mapper, F::now(...)), F::deployment(), $mapper);
        try { $bridge->respond([]); } catch (RuntimeException $actual) { H::check($actual === $exception); return; }
        throw new RuntimeException('Legacy exception was swallowed');
    },
    'models.legacy-unsupported-before-respond' => static function (): void {
        $port = new class implements LlmPort {
            public int $calls = 0;
            public function respond(array $input): array { ++$this->calls; return []; }
        };
        $transport = new LegacyLlmPortTransport($port, new LegacyFixtureMapper([]), F::now(...));
        $base = F::request();
        $request = new ModelRequest($base->invocationId, $base->messages, $base->contextSnapshotRef, $base->contextSnapshotHash,
            $base->credentialRef, $base->deadline, options: [new VisionOption(VisionDetail::High)]);
        H::rejects(static fn () => $transport->generate($request, F::deployment()), ContractViolation::class, ErrorCode::CapabilityUnavailable);
        H::rejects(static fn () => $transport->stream($base, F::deployment()), ContractViolation::class, ErrorCode::CapabilityUnavailable);
        H::check($port->calls === 0);
    },
    'models.legacy-scenario-boundary' => static function (): void {
        $methods = (new ReflectionClass(LlmPort::class))->getMethods();
        H::check(count($methods) === 1 && $methods[0]->getName() === 'respond');
        $run = new ReflectionMethod(\AgenTools\Scenario\ScenarioRunner::class, 'run');
        H::check((string) $run->getParameters()[2]->getType() === \AgenTools\Contracts\ToolPort::class);
        H::check(!(new ReflectionClass(\AgenTools\Scenario\ScenarioRunner::class))->hasMethod('respond'));
    },
];
