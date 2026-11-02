<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Knowledge\Tests\Invalidation;

use AgenToolsPlatform\Core\Common\{ContractViolation,Ref,RunId,UtcInstant};
use AgenToolsPlatform\Core\Context\{ContextAccess,ContextBlock,ContextSnapshot,Provenance,ReadContextCompiler,SnapshotAccess,SourceKind,TokenCounter};
use AgenToolsPlatform\Core\Context\History\{DerivedContextAccess,DerivedKind,Derivations,HistorySelector,MessageRef,SummaryVersion,SummaryViews,TextRead};
use AgenToolsPlatform\Core\Context\Memory\MemorySelector;
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Tests\Context\History\Fixture as History;
use PHPUnit\Framework\TestCase;

final class ContextTest extends TestCase
{
    private function rejected(\Closure $call): void
    { try { $call();self::fail('Expected fresh access failure'); } catch (ContractViolation) { self::assertTrue(true); } }
    public function testCachedSnapshotRechecksDependenciesAndOwner(): void
    {
        $h=new History();$f=$h->core;$summary=$h->summary();
        $block=new ContextBlock($summary->ref,'summary',$h->branch,'1',$h->branch,'untrusted','private',
            $f->context->purpose,$summary->fields->viewRef,$h->extractor->text,$summary->expiresAt,false,20,$f->hasher,[],new Provenance(SourceKind::Summary));
        $compiler=new ReadContextCompiler(new DerivedContextAccess($f,$h->store),$f->hasher);
        $plan=$compiler->buildPlan($f->context,$f->turn,$f->run,$f->bundle,
            [$f->block('policy','policy','Guard',true),$block,$f->block('input','input','Question',true)],$f->now);
        $compiled=$compiler->finalize($plan,$f->target(),$f,$f->now);$historic=$compiled->manifestJson;
        $access=new class implements SnapshotAccess {
            public bool $allowed=true;
            public function permits(ExecutionContext $context,ContextSnapshot $snapshot): bool { return $this->allowed; }
        };
        $compiler->authorizeReuse($plan,$compiled,$access,$f->now);self::assertTrue(true);
        $this->rejected(fn()=>$compiler->authorizeReuse($plan,$compiled,$access,new UtcInstant('2031-01-01T00:00:00Z')));
        $changed=new ContextBlock($block->ref,'summary',$block->source,'changed-version',$block->owner,'untrusted','private',
            $block->purpose,$block->contentRef,$block->modelView,$block->expiresAt,false,20,$f->hasher,[],$block->provenance);
        $changedPlan=$compiler->buildPlan($f->context,$f->turn,$f->run,$f->bundle,
            [$f->block('policy','policy','Guard',true),$changed,$f->block('input','input','Question',true)],$f->now);
        $this->rejected(fn()=>$compiler->authorizeReuse($changedPlan,$compiled,$access,$f->now));
        $access->allowed=false;$this->rejected(fn()=>$compiler->authorizeReuse($plan,$compiled,$access,$f->now));
        $access->allowed=true;$f->denied[]='policy';$this->rejected(fn()=>$compiler->authorizeReuse($plan,$compiled,$access,$f->now));$f->denied=[];
        $h->store->invalidate($f->context,$summary->sources->messages[0],'source_corrected');
        $this->rejected(fn()=>$compiler->authorizeReuse($plan,$compiled,$access,$f->now));
        self::assertSame($historic,$compiled->manifestJson);
    }
    public function testHistoryRevokeDuringViewAndMissingSource(): void
    {
        $h=new History();$f=$h->core;$summary=$h->summary();
        $views=new class($h) implements SummaryViews {
            public function __construct(private History $h) {}
            public function read(ExecutionContext $context,SummaryVersion $summary): TextRead
            {
                $this->h->store->invalidate($context,$summary->sources->messages[0],'source_deleted');
                return $this->h->extractor->read($context,$summary);
            }
        };
        $selector=new HistorySelector($h->source,$h->host,$h->store,$views,$f->hasher);
        $result=$selector->select($f->context,$h->branch,$h->state,$summary,[],$f->now);
        self::assertNotContains('summary',array_map(fn($b)=>$b->kind,$result->blocks));
        self::assertSame('source_revoked',$result->exclusions[0]['reason']);
        $h=new History();$summary=$h->summary();unset($h->source->messages['message:1']);
        $result=$h->selector()->select($h->core->context,$h->branch,$h->state,$summary,[],$h->core->now);
        self::assertNotContains('summary',array_map(fn($b)=>$b->kind,$result->blocks));
    }
    public function testMemoryRechecksAfterExternalView(): void
    {
        $h=new History();$candidate=$h->activate($h->candidate());
        $guard=new class($h) implements Derivations {
            private int $calls=0;
            public function __construct(private History $h) {}
            public function register(ExecutionContext $context,Ref $derived,DerivedKind $kind,array $sources,array $completedRuns=[]): void
            { $this->h->store->register($context,$derived,$kind,$sources,$completedRuns); }
            public function usable(ExecutionContext $context,Ref $derived): bool
            {
                if (++$this->calls===2) { $this->h->store->invalidate($context,$this->h->source->messages['message:1']->source,'source_revoked'); }
                return $this->h->store->usable($context,$derived);
            }
            public function invalidate(ExecutionContext $context,MessageRef $source,string $reason): void { $this->h->store->invalidate($context,$source,$reason); }
        };
        $selector=new MemorySelector($h->memory,$h->host,$h->source,$h->host,$guard,$h->core->hasher);
        $this->rejected(fn()=>$selector->select($h->core->context,$candidate->subject,$h->core->now));
    }
}
