<?php

declare(strict_types=1);
require_once __DIR__.'/Fixture.php';

use AgenToolsPlatform\Conformance\Host\Fixture;
use AgenToolsPlatform\Core\Common\IntegrityConflict;
use AgenToolsPlatform\Laravel\Managed\Input;
use AgenToolsPlatform\Tests\Harness as H;

H::run([
    'ECO-HOST-001' => static function (): void {
        $f = new Fixture(); $first = $f->intake->accept($f->envelope);
        for ($i = 0; $i < 2; ++$i) {
            $ack = $f->intake->accept($f->boundary->open(new Input('input-a')));
            H::check($ack === $first && $ack->toArray() === $first->toArray());
        }
        H::check($f->intake->count() === 1 && $first->turnId->value !== '' && $first->runId->value !== '');
    },
    'ECO-HOST-002' => static function (): void {
        $f = new Fixture(); $first = $f->intake->accept($f->envelope);
        $f->host->fixture['requests']['input-a']['payload'] = 'different content';
        try { $f->intake->accept($f->boundary->open(new Input('input-a'))); throw new RuntimeException('conflict missing'); }
        catch (IntegrityConflict $error) { H::check($error->getMessage() === 'SOURCE_PAYLOAD_CONFLICT'); }
        H::check($f->intake->count() === 1);
        $f->host->fixture['requests']['input-a']['payload'] = 'explain the report';
        H::check($f->intake->accept($f->boundary->open(new Input('input-a'))) === $first);
    },
    'host.source-tenant-instance-kind-scope' => static function (): void {
        $f = new Fixture(); $ids = [$f->intake->accept($f->envelope)->runId->value];
        $ids[] = $f->intake->accept($f->boundary->open(new Input('input-b')))->runId->value;
        $f->host->fixture['requests']['input-a']['instance'] = 'a2';
        $ids[] = $f->intake->accept($f->boundary->open(new Input('input-a')))->runId->value;
        $f->host->fixture['requests']['input-a']['source_kind'] = 'host.voice';
        $ids[] = $f->intake->accept($f->boundary->open(new Input('input-a')))->runId->value;
        H::check(count(array_unique($ids)) === 4 && $f->intake->count() === 4);
    },
]);
