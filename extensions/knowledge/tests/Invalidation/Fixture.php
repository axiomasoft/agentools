<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Invalidation;

use AgenToolsPlatform\Knowledge\{Decision, Integrity, Scope};
use AgenToolsPlatform\Knowledge\Dependencies\{DependencyPolicy, DeletionRequest, DeletionResult, DeletionStore, DeletionTransport, Node};

final class Policy implements DependencyPolicy
{
    public Decision $decision = Decision::Allowed;
    public ?\PDO $pdo = null;
    public function check(Scope $scope, Node $node, string $operation): Decision
    {
        if ($this->pdo?->inTransaction()) { throw new \LogicException('Owner IO inside transaction'); }
        return $this->decision;
    }
}
final class Transport implements DeletionTransport
{
    public int $calls = 0;
    public array $keys = [];
    public DeletionResult $next;
    public ?\PDO $pdo = null;
    public bool $throws = false;
    public function __construct() { $this->next = new DeletionResult('succeeded','provider:receipt'); }
    public function delete(DeletionRequest $request): DeletionResult
    {
        if ($this->pdo?->inTransaction()) { throw new \LogicException('Provider IO inside transaction'); }
        ++$this->calls; $this->keys[]=$request->id;
        if ($this->throws) { throw new \RuntimeException('private provider error'); }
        return $this->next;
    }
}
/** Scripted attempt boundary only. Durable store proof is in GraphTest. */
final class Attempts implements DeletionStore
{
    public array $started = [];
    public array $results = [];
    public function beginAttempt(Scope $scope, DeletionRequest $request, string $attempt): bool
    {
        $key=$scope->tenant.':'.$request->id.':'.$attempt;
        if (isset($this->started[$key])) { return false; }
        $this->started[$key]=true;return true;
    }
    public function result(Scope $scope, DeletionRequest $request, string $attempt): ?DeletionResult
    { return $this->results[$scope->tenant.':'.$request->id.':'.$attempt]??null; }
    public function finishAttempt(Scope $scope, DeletionRequest $request, string $attempt, DeletionResult $result): void
    { $this->results[$scope->tenant.':'.$request->id.':'.$attempt]=$result; }
}
final class Fixture
{
    public static function scope(string $tenant='tenant:a'): Scope { return new Scope($tenant,'actor:a','students','education',['read']); }
    public static function node(string $kind='material',string $ref='source:1',string $version='v1',string $tenant='tenant:a',string $content='original'): Node
    { return new Node($tenant,$kind,$ref,$version,hash('sha256',$content)); }
    public static function observation(string $name,array $value): void
    {
        $path=getenv('AGENTOOLS_INVALIDATION_OBSERVATIONS');
        if (!is_string($path) || $path==='') { throw new \RuntimeException('Observation path missing'); }
        file_put_contents($path,json_encode([$name=>$value],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
    }
}
