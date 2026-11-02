<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Dependencies;

use AgenToolsPlatform\Knowledge\{Decision, Integrity, Scope, Wire};
use PDO;

/** Dedicated PostgreSQL boundary. All graph writes serialize per tenant; owner IO stays outside transactions. */
final readonly class PostgresGraph implements DeletionStore
{
    public function __construct(private PDO $pdo, private string $schema, private ?DependencyPolicy $policy = null)
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql'
            || preg_match('/\A[a-z][a-z0-9_]{0,62}\z/D',$schema) !== 1) {
            throw new \InvalidArgumentException('PostgreSQL and safe schema required');
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    }

    public function install(): void { $this->transaction(fn() => $this->installInside()); }
    /** Repository composition only: same connection, transaction and search_path as its owning adapter. */
    public function installInside(): void
    {
        $this->inside(); $sql = file_get_contents(__DIR__.'/schema.sql');
        if ($sql === false) { throw new \RuntimeException('Dependency schema missing'); }
        $this->pdo->exec($sql);
    }

    /** @param list<Node> $parents */
    public function register(Scope $scope, Node $node, array $parents = []): void
    {
        $this->authorize($scope,$node,'register');
        foreach ($parents as $parent) { $this->authorize($scope,$parent,'use'); }
        $this->transaction(fn() => $this->registerInside($node,$parents));
    }

    /** @param list<Node> $parents */
    public function registerInside(Node $node, array $parents = []): void
    {
        $this->inside(); $this->lock($node->tenant);
        if (!array_is_list($parents) || count($parents) > 4096) { throw new \InvalidArgumentException('Bounded parent list required'); }
        $keys = [];
        foreach ($parents as $parent) {
            if ($parent->tenant !== $node->tenant || $parent->key() === $node->key() || isset($keys[$parent->key()])) {
                throw new \DomainException('Foreign, duplicate or cyclic dependency');
            }
            $this->requireActiveInside($parent); $keys[$parent->key()] = $parent->hash;
        }
        ksort($keys); $parentsHash = Integrity::digest($keys);
        $this->execute('INSERT INTO dependency_nodes VALUES (?,?,?,?,?,?,?) ON CONFLICT DO NOTHING',
            [$node->tenant,$node->key(),$node->kind,$node->ref,$node->version,$node->hash,$parentsHash]);
        $row = $this->nodeInside($node);
        if ($row === null || $row['content_hash'] !== $node->hash || $row['parents_hash'] !== $parentsHash) {
            throw new \DomainException('Dependency identity hash or parent-set conflict');
        }
        foreach (array_keys($keys) as $key) {
            $this->execute('INSERT INTO dependency_edges VALUES (?,?,?) ON CONFLICT DO NOTHING',[$node->tenant,$node->key(),$key]);
        }
        $this->requireActiveInside($node);
    }

    public function usable(Scope $scope, Node $node): Decision
    {
        $this->idle();
        if ($scope->tenant !== $node->tenant) { return Decision::Denied; }
        $decision = $this->policy?->check($scope,$node,'use') ?? Decision::Unknown;
        if ($decision !== Decision::Allowed) { return $decision; }
        $active = $this->transaction(function () use ($node): bool {
            $this->lock($node->tenant); return $this->activeInside($node);
        });
        return $active ? Decision::Allowed : Decision::Denied;
    }

    public function invalidate(Scope $scope, Node $source, string $reason, string $eventId): void
    {
        $this->authorize($scope,$source,'invalidate');
        $this->transaction(fn() => $this->invalidateInside($source,$reason,$eventId));
    }

    public function invalidateInside(Node $source, string $reason, string $eventId): void
    {
        $this->inside(); $this->lock($source->tenant); Integrity::text($eventId);
        if (!in_array($reason,['source_corrected','source_deleted','source_revoked','consent_revoked'],true)) {
            throw new \InvalidArgumentException('Known source event required');
        }
        $row = $this->nodeInside($source);
        if ($row === null) {
            // Tombstone an as-yet unseen root before any late registration arrives.
            if (!in_array($source->kind,['material','message'],true)) { throw new \DomainException('Unknown derivative'); }
            $this->execute('INSERT INTO dependency_nodes VALUES (?,?,?,?,?,?,?)',
                [$source->tenant,$source->key(),$source->kind,$source->ref,$source->version,$source->hash,Integrity::digest([])]);
        } elseif ($row['content_hash'] !== $source->hash) { throw new \DomainException('Source hash conflict'); }
        $hash = Integrity::digest([$source,$reason]);
        $this->execute('INSERT INTO dependency_tombstones VALUES (?,?,?,?,?) ON CONFLICT DO NOTHING',
            [$source->tenant,$eventId,$hash,$source->key(),$reason]);
        $event = $this->row('SELECT event_hash FROM dependency_tombstones WHERE tenant=? AND event_id=?',[$source->tenant,$eventId]);
        if ($event === null || $event['event_hash'] !== $hash) { throw new \DomainException('Source event identity conflict'); }
        // Also traverse other exact versions for identity-wide delete/revoke.
        $this->execute('WITH RECURSIVE affected(node_key) AS (
            SELECT node_key FROM dependency_nodes WHERE tenant=? AND kind=? AND ref=? AND (?<>\'source_corrected\' OR version=?)
            UNION SELECT e.child FROM dependency_edges e JOIN affected a ON e.parent=a.node_key WHERE e.tenant=?
        ) INSERT INTO dependency_deletions SELECT r.tenant,r.request_id FROM dependency_resources r
            JOIN affected a ON a.node_key=r.node_key WHERE r.tenant=? ON CONFLICT DO NOTHING',
            [$source->tenant,$source->kind,$source->ref,$reason,$source->version,$source->tenant,$source->tenant]);
    }

    /** @param non-empty-list<Node> $nodes */
    public function recordUse(Scope $scope, string $run, array $nodes): void
    {
        Integrity::text($run);
        if (!array_is_list($nodes) || $nodes === [] || count($nodes)>4096) { throw new \InvalidArgumentException('Bounded run dependencies required'); }
        foreach ($nodes as $node) { $this->authorize($scope,$node,'record_use'); }
        $this->transaction(function () use ($scope,$run,$nodes): void {
            $this->lock($scope->tenant); $keys=[];
            foreach ($nodes as $node) {
                if (isset($keys[$node->key()])) { throw new \DomainException('Duplicate run dependency'); }
                $keys[$node->key()]=$node->hash;
            }
            ksort($keys); $hash=Integrity::digest($keys);
            $old=$this->row('SELECT content_hash FROM dependency_runs WHERE tenant=? AND run_ref=?',[$scope->tenant,$run]);
            if ($old !== null) {
                if ($old['content_hash'] !== $hash) { throw new \DomainException('Run dependency identity conflict'); }
                return; // Historical replay after revocation cannot authorize another invocation.
            }
            foreach ($nodes as $node) { $this->requireActiveInside($node); }
            $this->execute('INSERT INTO dependency_runs VALUES (?,?,?)',[$scope->tenant,$run,$hash]);
            foreach (array_keys($keys) as $key) { $this->execute('INSERT INTO dependency_run_nodes VALUES (?,?,?)',[$scope->tenant,$run,$key]); }
        });
    }

    /** @return list<array{source:Node,reason:string,event_id:string}> */
    public function markers(Scope $scope, string $run): array
    {
        Integrity::text($run);
        // No source identifiers are returned before the host authorizes this run reference.
        $this->authorize($scope,new Node($scope->tenant,'context',$run,'1',Integrity::digest($run)),'history');
        return $this->transaction(function () use ($scope,$run): array {
            $statement=$this->execute('WITH RECURSIVE ancestors(node_key) AS (
                SELECT node_key FROM dependency_run_nodes WHERE tenant=? AND run_ref=?
                UNION SELECT e.parent FROM dependency_edges e JOIN ancestors a ON e.child=a.node_key WHERE e.tenant=?
            ) SELECT DISTINCT s.*,t.reason,t.event_id FROM ancestors a
                JOIN dependency_nodes n ON n.tenant=? AND n.node_key=a.node_key
                JOIN dependency_nodes s ON s.tenant=n.tenant AND s.kind=n.kind AND s.ref=n.ref
                JOIN dependency_tombstones t ON t.tenant=s.tenant AND t.source=s.node_key
                WHERE (t.reason<>\'source_corrected\' OR s.version=n.version) ORDER BY t.event_id',
                [$scope->tenant,$run,$scope->tenant,$scope->tenant]);
            $out=[];
            while (($row=$statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                $out[]=['source'=>$this->decodeNode($row),'reason'=>Wire::string($row,'reason'),'event_id'=>Wire::string($row,'event_id')];
            }
            return $out;
        });
    }

    public function attach(Scope $scope, DeletionRequest $request): void
    {
        $this->authorize($scope,$request->node,'attach');
        $this->transaction(function () use ($request): void {
            $this->lock($request->node->tenant); $this->requireActiveInside($request->node);
            $payload=json_encode([$request->node,$request->providerRef,$request->support,$request->knownExpiry],JSON_THROW_ON_ERROR);
            $this->execute('INSERT INTO dependency_resources VALUES (?,?,?,?,?) ON CONFLICT DO NOTHING',
                [$request->node->tenant,$request->id,$request->node->key(),$payload,$request->hash()]);
            $row=$this->row('SELECT content_hash FROM dependency_resources WHERE tenant=? AND request_id=?',[$request->node->tenant,$request->id]);
            if ($row === null || $row['content_hash'] !== $request->hash()) { throw new \DomainException('Provider resource identity conflict'); }
        });
    }

    /** @return list<DeletionRequest> */
    public function requests(Scope $scope, int $limit = 64, ?string $after = null): array
    {
        if ($limit<1 || $limit>256) { throw new \InvalidArgumentException('Bounded deletion read required'); }
        if ($after !== null) { Integrity::hash($after); }
        $requests=$this->transaction(function () use ($scope,$limit,$after): array {
            $rows=$this->execute('SELECT r.payload,r.content_hash FROM dependency_resources r JOIN dependency_deletions d
                USING(tenant,request_id) WHERE r.tenant=? AND r.request_id>? ORDER BY request_id LIMIT '.$limit,[$scope->tenant,$after ?? '']);
            $out=[];
            while (($row=$rows->fetch(PDO::FETCH_ASSOC)) !== false) {
                $data=json_decode(Wire::string($row,'payload'),true,64,JSON_THROW_ON_ERROR);
                if (!is_array($data) || count($data)!==4 || !is_array($data[0])
                    || !is_string($data[1]) || !is_string($data[2]) || ($data[3]!==null && !is_string($data[3]))) {
                    throw new \UnexpectedValueException('Corrupt resource');
                }
                $n=Wire::object($data[0]); $node=new Node(Wire::string($n,'tenant'),Wire::string($n,'kind'),Wire::string($n,'ref'),Wire::string($n,'version'),Wire::string($n,'hash'));
                $request=new DeletionRequest($node,$data[1],$data[2],$data[3]);
                if ($request->hash() !== $row['content_hash'] || $node->tenant !== $scope->tenant) { throw new \UnexpectedValueException('Resource hash/scope mismatch'); }
                $out[]=$request;
            }
            return $out;
        });
        foreach ($requests as $request) { $this->authorize($scope,$request->node,'delete'); }
        return $requests;
    }

    public function beginAttempt(Scope $scope, DeletionRequest $request, string $attempt): bool
    {
        $this->authorize($scope,$request->node,'delete'); Integrity::text($attempt);
        return $this->transaction(function () use ($request,$attempt): bool {
            $this->lock($request->node->tenant); $this->requestInside($request);
            return $this->execute('INSERT INTO dependency_delete_attempts VALUES (?,?,?,?) ON CONFLICT DO NOTHING',
                [$request->node->tenant,$request->id,$attempt,$request->hash()])->rowCount() === 1;
        });
    }
    public function result(Scope $scope, DeletionRequest $request, string $attempt): ?DeletionResult
    {
        $this->authorize($scope,$request->node,'delete'); Integrity::text($attempt);
        return $this->transaction(function () use ($request,$attempt): ?DeletionResult {
            $this->requestInside($request);
            $row=$this->row('SELECT status,receipt_ref,content_hash FROM dependency_delete_results WHERE tenant=? AND request_id=? AND attempt=?',
                [$request->node->tenant,$request->id,$attempt]);
            if ($row === null) { return null; }
            $receipt=$row['receipt_ref'];
            if ($receipt !== null && !is_string($receipt)) { throw new \UnexpectedValueException('Receipt ref invalid'); }
            $result=new DeletionResult(Wire::string($row,'status'),$receipt);
            if (Integrity::digest($result) !== $row['content_hash']) { throw new \UnexpectedValueException('Deletion result hash mismatch'); }
            return $result;
        });
    }
    public function finishAttempt(Scope $scope, DeletionRequest $request, string $attempt, DeletionResult $result): void
    {
        $this->authorize($scope,$request->node,'delete'); Integrity::text($attempt);
        $this->transaction(function () use ($request,$attempt,$result): void {
            $this->lock($request->node->tenant); $this->requestInside($request); $hash=Integrity::digest($result);
            $this->execute('INSERT INTO dependency_delete_results VALUES (?,?,?,?,?,?) ON CONFLICT DO NOTHING',
                [$request->node->tenant,$request->id,$attempt,$result->status,$result->receiptRef,$hash]);
            $row=$this->row('SELECT content_hash FROM dependency_delete_results WHERE tenant=? AND request_id=? AND attempt=?',
                [$request->node->tenant,$request->id,$attempt]);
            if ($row === null || $row['content_hash'] !== $hash) { throw new \DomainException('Deletion result identity conflict'); }
        });
    }

    private function requestInside(DeletionRequest $request): void
    {
        $row=$this->row('SELECT r.content_hash FROM dependency_resources r JOIN dependency_deletions d USING(tenant,request_id)
            WHERE r.tenant=? AND r.request_id=?',[$request->node->tenant,$request->id]);
        if ($row === null || $row['content_hash'] !== $request->hash() || $this->activeInside($request->node)) {
            throw new \DomainException('Durable locally invalid deletion intent required');
        }
    }
    public function requireActiveInside(Node $node): void
    {
        $this->inside(); $this->lock($node->tenant);
        if (!$this->activeInside($node)) { throw new \DomainException('Dependency missing, changed or invalidated'); }
    }
    private function activeInside(Node $node): bool
    {
        $row=$this->nodeInside($node);
        if ($row === null || $row['content_hash'] !== $node->hash) { return false; }
        return $this->row('WITH RECURSIVE ancestors(node_key) AS (
            SELECT node_key FROM dependency_nodes WHERE tenant=? AND node_key=?
            UNION SELECT e.parent FROM dependency_edges e JOIN ancestors a ON a.node_key=e.child WHERE e.tenant=?
        ) SELECT t.reason FROM ancestors a JOIN dependency_nodes n ON n.tenant=? AND n.node_key=a.node_key
            JOIN dependency_nodes s ON s.tenant=n.tenant AND s.kind=n.kind AND s.ref=n.ref
            JOIN dependency_tombstones t ON t.tenant=s.tenant AND t.source=s.node_key
            WHERE (t.reason<>\'source_corrected\' OR n.version=s.version) LIMIT 1',
            [$node->tenant,$node->key(),$node->tenant,$node->tenant]) === null;
    }
    /** @return array<string,mixed>|null */
    private function nodeInside(Node $node): ?array
    { return $this->row('SELECT content_hash,parents_hash FROM dependency_nodes WHERE tenant=? AND node_key=?',[$node->tenant,$node->key()]); }
    /** @param array<string,mixed> $row */
    private function decodeNode(array $row): Node
    { return new Node(Wire::string($row,'tenant'),Wire::string($row,'kind'),Wire::string($row,'ref'),Wire::string($row,'version'),Wire::string($row,'content_hash')); }
    private function authorize(Scope $scope, Node $node, string $operation): void
    {
        $this->idle();
        if ($scope->tenant !== $node->tenant || $this->policy?->check($scope,$node,$operation) !== Decision::Allowed) {
            throw new \DomainException('Current dependency permission denied or unknown');
        }
    }
    private function lock(string $tenant): void
    { $this->execute('SELECT pg_advisory_xact_lock(hashtextextended(?,0))',[Integrity::digest(['dependencies/v1',$tenant])]); }
    private function idle(): void
    { if ($this->pdo->inTransaction()) { throw new \LogicException('Dependency graph owns its transaction boundary'); } }
    private function inside(): void
    { if (!$this->pdo->inTransaction()) { throw new \LogicException('Repository transaction required'); } }
    /** @template T
     * @param \Closure():T $operation
     * @return T
     */
    private function transaction(\Closure $operation): mixed
    {
        $this->idle(); $this->pdo->beginTransaction();
        try {
            $this->pdo->exec('SET LOCAL search_path TO "'.$this->schema.'", pg_catalog');
            $result=$operation(); $this->pdo->commit(); return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) { $this->pdo->rollBack(); }
            throw $error;
        }
    }
    /** @param list<string|null> $parameters */
    private function execute(string $sql, array $parameters): \PDOStatement
    { $statement=$this->pdo->prepare($sql); $statement->execute($parameters); return $statement; }
    /** @param list<string|null> $parameters
     * @return array<string,mixed>|null
     */
    private function row(string $sql, array $parameters): ?array
    {
        $row=$this->execute($sql,$parameters)->fetch(PDO::FETCH_ASSOC);
        if ($row === false) { return null; }
        /** @var array<string,mixed> $row */
        return $row;
    }
}
