<?php

declare(strict_types=1);

use AgenTools\Action\{ActionEvent, ActionJournal, ActionReducer, Actor, FoldedAction, JournalHashChain, LifecycleException, StaleExecutorEpoch, VersionConflict};
use AgenTools\Schema\{Canonicalizer, PublishedSchemaValidator, SchemaValidationException};

require_once dirname(__DIR__,2) . '/php/vendor/autoload.php';
$consumer = $argv[1] ?? '';
if (!in_array($consumer,['php','reference'],true)) { throw new InvalidArgumentException('Explicit consumer required'); }
$selected = array_slice($argv,2);
$root = dirname(__DIR__,2);
$vectors = json_decode((string)file_get_contents($root.'/fixtures/platform/v1/compatibility/vectors.json'),true,512,JSON_THROW_ON_ERROR);
$schemas = new PublishedSchemaValidator($root.'/spec/platform/v1/schema');
$rows = [];
foreach ($vectors['cases'] as $case) {
    if ($selected !== [] && !in_array($case['id'],$selected,true)) { continue; }
    if ($case['kind'] === 'schema') {
        $fixture = json_decode((string)file_get_contents($root.'/'.$case['source']),true,512,JSON_THROW_ON_ERROR);
        $valid = true;
        try { $schemas->assertValid($fixture['data'],'urn:agentools:platform:v1:contracts#/$defs/'.$fixture['definition']); }
        catch (SchemaValidationException) { $valid = false; }
        $observed = ['valid'=>$valid,'data'=>$fixture['data']];
        $passed = $valid === $fixture['expect']['valid'];$tier = 'design-schema';
    } else {
        $reducer = new ActionReducer(hashChain:new JournalHashChain(new Canonicalizer()));
        $state = new FoldedAction();$journal = new ActionJournal();$rejection = null;
        foreach ($case['events'] as $index=>$raw) {
            $seq = $index+1;
            $event = new ActionEvent('event-'.$seq,'dated-action-1',$seq,$raw['expected_prev_seq'] ?? $seq-1,
                '2026-10-31T12:00:00Z',new Actor('host','owner-1'),$raw['kind'],1,'2026-09',$raw['payload'] ?? [],$raw['epoch'] ?? null,$state->previousHash);
            try {
                if ($consumer === 'reference') { $journal = $journal->append($event,$reducer);$state = $journal->state($reducer); }
                else { $state = $reducer->apply($state,$event); }
            } catch (LifecycleException $error) {
                $rejection = match (true) { $error instanceof StaleExecutorEpoch=>'stale_epoch',$error instanceof VersionConflict=>'version_conflict',default=>'lifecycle' };break;
            }
        }
        $observed = ['status'=>$state->status?->value,'seq'=>$state->seq,'epoch'=>$state->epoch,
            'cancellation_requested'=>$state->cancellationRequested,'unresolved_effects'=>$state->hasUnresolvedEffect(),'rejection'=>$rejection];
        $passed = $observed === $case['expected'];
        $observed['previous_hash'] = $state->previousHash;$tier = 'contract-scripted';
    }
    $rows[] = ['id'=>$case['id'],'status'=>$passed?'passed':'failed','tier'=>$tier,'observed'=>$observed];
}
if ($rows === [] || ($selected !== [] && count($rows)!==count($selected))) { throw new RuntimeException('Missing selected cases'); }
echo json_encode(['consumer'=>$consumer,'cases'=>$rows],JSON_THROW_ON_ERROR).PHP_EOL;
exit(in_array('failed',array_column($rows,'status'),true)?1:0);
