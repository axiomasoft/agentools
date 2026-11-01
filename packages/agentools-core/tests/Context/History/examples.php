<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';
require_once dirname(__DIR__,5).'/fixtures/platform/v1/history/external-owner-adapter.php';

use AgenToolsExamples\History\{ExternalHistoryAdapter,MessengerHistoryPort};
use AgenToolsPlatform\Core\Common\{ContractViolation,ErrorCode,Ref};
use AgenToolsPlatform\Core\Context\History\{HistoryPage,MessageRead,MessageRef,SourceManifest};
use AgenToolsPlatform\Core\Security\ExecutionContext;
use AgenToolsPlatform\Tests\{Harness as H,TestHost};
use AgenToolsPlatform\Tests\Context\Fixture as Values;
use AgenToolsPlatform\Tests\Context\History\{Fixture,Transcript};

H::run(['history.generated-owner-example'=>static function(): void {
    $f=new Fixture();$root=dirname(__DIR__,5).'/fixtures/platform/v1/history';$summary=$f->summary();
    foreach (['working-state.json'=>$f->state->toArray(),'summary.json'=>$summary->toArray(),'memory-candidate.json'=>$f->candidate()->toArray()] as $name=>$expected) {
        $wire=file_get_contents($root.'/'.$name);if ($wire===false) { throw new RuntimeException('Required example missing'); }
        H::check(Values::object(json_decode($wire,true,64,JSON_THROW_ON_ERROR))===$expected);
    }
    $owner=new class($f->source) implements MessengerHistoryPort {
        public function __construct(private Transcript $source) {}
        public function recent(ExecutionContext $context,Ref $branch,int $afterTurn,int $limit): HistoryPage { return $this->source->recent($context,$branch,$afterTurn,$limit); }
        public function manifest(ExecutionContext $context,Ref $branch,int $throughTurn): SourceManifest { return $this->source->manifest($context,$branch,$throughTurn); }
        public function read(ExecutionContext $context,MessageRef $ref): MessageRead { return $this->source->read($context,$ref); }
    };
    $host=new TestHost($f->core->tenant);$adapter=new ExternalHistoryAdapter($owner,$host);
    H::check($adapter->recent($f->core->context,$f->branch,10,2)->messages[0]->source->turn===11);
    $host->denied=true;$reads=count($f->source->reads);
    H::rejects(static fn()=> $adapter->read($f->core->context,$f->source->messages['message:1']->source),ContractViolation::class,ErrorCode::AccessDenied);
    H::check(count($f->source->reads)===$reads);
}]);
