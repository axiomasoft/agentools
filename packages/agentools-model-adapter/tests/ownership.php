<?php

declare(strict_types=1);

require_once __DIR__ . '/MappingHarness.php';
require_once __DIR__ . '/TargetPin.php';

use AgenToolsPlatform\Tests\Harness as H;
use AgenToolsPlatform\ModelAdapterTests\{Fixture as F, HttpScript, MappingHarness, TargetPin};
use AgenToolsPlatform\Core\Common\{ActionId, ProposalBinding, ProposalId, PayloadHash, IntegrityConflict};
use AgenToolsPlatform\Core\Models\{ContentPart, ModelMessage, ModelRequest, MessageRole, FinishReason};
use AgenToolsPlatform\ModelAdapter\Anthropic\Transport;
use AgenToolsPlatform\ModelAdapter\Http\HttpEvent;

return [
 'SDK-001' => static function (): void {
    $f = new F(); $calls = 0; $effects = 0;
    $http = new HttpScript(static function () use (&$calls): iterable { ++$calls; return [HttpEvent::headers(200), HttpEvent::body(json_encode($calls === 1 ? F::proposal() : F::message(), JSON_THROW_ON_ERROR))]; });
    $driver = $f->transport($http); $request = $f->request(); $response = $driver->generate($request, $f->deployment());
    H::check($calls === 1 && $response->finishReason === FinishReason::ToolProposal && (new ReflectionClass($driver))->getConstant('LOOP_OWNER') === 'AgenTools Runtime');
    $proposal = $response->proposals[0];
    $mapping = new ProposalBinding(new ProposalId($proposal->invocationId->tenant, 'proposal:invocation-1:' . $proposal->providerProposalRef), new ActionId($proposal->invocationId->tenant, 'action-1'), new PayloadHash('sha-256:' . hash('sha256', json_encode($proposal->arguments->value, JSON_THROW_ON_ERROR))), null);
    $file = tempnam(sys_get_temp_dir(), 'agentools_model_mapping_');
    if ($file === false) { throw new RuntimeException('Cannot create test mapping'); }
    try {
      $journal = new MappingHarness($file);
      $effect = static function () use (&$effects, $file): string {
        $other = new PDO('sqlite:' . $file);
        $statement = $other->query("SELECT state FROM mappings");
        if ($statement === false) { throw new RuntimeException('Failed to inspect committed intent'); }
        H::check($statement->fetchColumn() === 'unknown', 'Intent not visible on independent connection');
        ++$effects; return 'read-result';
      };
      H::rejects(static fn () => $journal->dispatch($mapping, $effect), RuntimeException::class);
      H::check($effects === 0);
      $journal->save($mapping); unset($journal);
      $journal = new MappingHarness($file); H::check($journal->dispatch($mapping, $effect) === 'read-result'); unset($journal);
      $journal = new MappingHarness($file); H::check($journal->dispatch($mapping, $effect) === 'read-result' && $effects === 1);
      $messages = [...$request->messages, new ModelMessage(MessageRole::Assistant, [], proposals: [$proposal]), new ModelMessage(MessageRole::Tool, [ContentPart::text('read-result')], $proposal->providerProposalRef)];
      $next = new ModelRequest(F::invocation('invocation-2'), $messages, $request->contextSnapshotRef, $request->contextSnapshotHash, $request->credentialRef, $request->deadline, tools: $request->tools);
      H::check($driver->generate($next, $f->deployment())->finishReason === FinishReason::Completed && $calls === 2 && $effects === 1);
      $body = F::body($http->requests[1]);
      H::check(F::at(F::at($body, 'messages', 1), 'content', 0)->id === 'toolu-stable' && F::at(F::at($body, 'messages', 2), 'content', 0)->tool_use_id === 'toolu-stable');
    } finally { unlink($file); }
 },
 'V2ALL-SDK-NEGATIVE' => static function (): void {
    $f = new F(); $http = F::jsonHttp(F::message()); $driver = $f->transport($http);
    H::rejects(static fn () => (new ReflectionClass($driver))->getMethod('complete'), ReflectionException::class);
    H::check($http->requests === [] && !(new ReflectionClass($driver))->hasMethod('executeTool'));
    $lock = json_decode((string) file_get_contents(dirname(__DIR__) . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);
    TargetPin::verify($lock);
    H::rejects(static fn () => TargetPin::verify(TargetPin::corrupt($lock, false)), RuntimeException::class);
    H::rejects(static fn () => TargetPin::verify(TargetPin::corrupt($lock, true)), RuntimeException::class);
 },
 'adapter.callback-retry-unknown-and-conflict' => static function (): void {
    $tenant = F::invocation()->tenant; $proposal = new ProposalId($tenant, 'proposal-1');
    $mapping = new ProposalBinding($proposal, new ActionId($tenant, 'action-1'), new PayloadHash('sha-256:' . str_repeat('a', 64)), null);
    $file = tempnam(sys_get_temp_dir(), 'agentools_model_mapping_');
    if ($file === false) { throw new RuntimeException('Cannot create test mapping'); }
    $effects = 0; $effect = static function () use (&$effects): string { ++$effects; return 'effect-result'; };
    try {
      $journal = new MappingHarness($file); $journal->save($mapping);
      H::rejects(static fn () => $journal->save(new ProposalBinding($proposal, $mapping->actionId, new PayloadHash('sha-256:' . str_repeat('b', 64)), null)), IntegrityConflict::class);
      H::rejects(static fn () => $journal->dispatch($mapping, $effect, true), RuntimeException::class); unset($journal);
      $journal = new MappingHarness($file);
      H::rejects(static fn () => $journal->dispatch($mapping, $effect), RuntimeException::class);
      H::check($effects === 1);
    } finally { unlink($file); }
 },
];
