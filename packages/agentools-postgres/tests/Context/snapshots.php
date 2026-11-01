<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, IntegrityConflict, Ref};
use AgenToolsPlatform\Core\Context\{CompiledContext, ContextSnapshot};
use AgenToolsPlatform\Core\Security\{ExecutionContext, HostAuthorization};
use AgenToolsPlatform\Postgres\Context\{Authority, Schema, SnapshotRepository};
use AgenToolsPlatform\Postgres\Runtime\{Connection, ContextRevision, RepositoryContext};
use AgenToolsPlatform\Postgres\Tests\IsolatedDatabase;
use AgenToolsPlatform\Tests\Context\Fixture;
use AgenToolsPlatform\Tests\{Harness as H,TestHost};

$database=new IsolatedDatabase(); $isolation=$database->verify();
$schema='p3_'.bin2hex(random_bytes(12)); $database->createSchema($schema);
$cases=[]; $failures=0;
try {
    Schema::install($database->pdo,$schema); Schema::install($database->pdo,$schema);
    $f=new Fixture(); $db=new Connection($database->pdo,$schema); $host=new TestHost($f->tenant);
    $authority=new class implements Authority {
        public bool $denied=false;
        public function authorize(ExecutionContext $context,ContextSnapshot $snapshot,string $operation): void {
            if ($this->denied) { throw new ContractViolation(ErrorCode::AccessDenied); }
        }
    };
    $revisions=new class implements ContextRevision { public function current(ExecutionContext $context,Ref $ref): int { return 1; } };
    $repo=new SnapshotRepository(new RepositoryContext($db,$f->hasher,$host,$revisions),$authority);
    $compiled=$f->compiler->finalize($f->plan([$f->block('background','summary',str_repeat('x',9000))]),$f->target(),$f,$f->now,$f);
    $tests=[
        'context.snapshot-durable-replay'=>static function() use($repo,$f,$compiled,$db): void {
            $one=$repo->put($f->context,$compiled); $two=$repo->put($f->context,$compiled);
            H::check($one->toArray() === $two->toArray() && $repo->get($f->context,$one) === $compiled->manifestJson);
            $row=$db->row('SELECT count(*) AS count FROM '.$db->table('context_snapshots'));
            H::check($row !== null && Connection::number($row,'count') === 1);
            $stored=$repo->get($f->context,$one);
            H::check(str_contains($stored,'input_budget') && !str_contains($stored,'Current question'));
        },
        'context.snapshot-current-scope'=>static function() use($repo,$f,$compiled,$host,$authority): void {
            $authority->denied=true;
            H::rejects(static fn()=> $repo->put($f->context,$compiled),ContractViolation::class,ErrorCode::AccessDenied);
            H::rejects(static fn()=> $repo->get($f->context,$compiled->snapshot),ContractViolation::class,ErrorCode::AccessDenied);
            $authority->denied=false; $host->revision='changed';
            H::rejects(static fn()=> $repo->get($f->context,$compiled->snapshot),ContractViolation::class,ErrorCode::StaleContext);
            $host->revision='policy-1';
            $foreign=new ContextSnapshot(new Ref(new \AgenToolsPlatform\Core\Common\TenantId('other'),$compiled->snapshot->ref->value),1,$compiled->snapshot->contentHash,$compiled->snapshot->scopeHash);
            H::rejects(static fn()=> $repo->get($f->context,$foreign),ContractViolation::class,ErrorCode::AccessDenied);
        },
        'context.snapshot-integrity-conflict'=>static function() use($repo,$f,$compiled,$db,$database,$schema): void {
            H::rejects(static fn()=> $repo->put($f->context,new CompiledContext($compiled->snapshot,'changed prompt',$compiled->manifestJson)),IntegrityConflict::class);
            $m=Fixture::object(json_decode($compiled->manifestJson,true,64,JSON_THROW_ON_ERROR));
            unset($m['snapshot_id'],$m['immutable_content_hash']); $m['policy_revision']='different';
            $hash=$f->hasher->hash($f->tenant,'context-snapshot',['manifest'=>$m,'rendered'=>$compiled->rendered]);
            $m['snapshot_id']=$compiled->snapshot->ref->value; $m['immutable_content_hash']=$hash->value;
            $conflict=new CompiledContext(new ContextSnapshot($compiled->snapshot->ref,1,$hash,$compiled->snapshot->scopeHash),$compiled->rendered,json_encode($m,JSON_THROW_ON_ERROR));
            H::rejects(static fn()=> $repo->put($f->context,$conflict),ContractViolation::class,ErrorCode::IntegrityConflict);
            H::check($repo->get($f->context,$compiled->snapshot) === $compiled->manifestJson);
            $db->execute('UPDATE '.$db->table('context_snapshots')." SET manifest_json='{}'");
            H::rejects(static fn()=> $repo->get($f->context,$compiled->snapshot),ContractViolation::class,ErrorCode::IntegrityConflict);
            $db->execute('UPDATE '.$db->table('context_schema_versions')." SET source_hash='tampered'");
            H::rejects(static fn()=> Schema::install($database->pdo,$schema),IntegrityConflict::class);
        },
    ];
    foreach ($tests as $id=>$test) {
        try { $test(); $cases[]=['id'=>$id,'status'=>'passed']; }
        catch (Throwable $error) { ++$failures; $cases[]=['id'=>$id,'status'=>'failed']; fwrite(STDERR,$id.': '.$error::class.': '.$error->getMessage().PHP_EOL); }
    }
} finally { $database->dropSchema($schema); }
$isolation['case_ids']=array_column($cases,'id');
echo json_encode(['mode'=>'isolated-postgres','tier'=>'isolated-postgres','cases'=>$cases,'count'=>count($cases),'failures'=>$failures,'skips'=>0,'isolation'=>$isolation],JSON_THROW_ON_ERROR).PHP_EOL;
exit($failures === 0 ? 0 : 1);
