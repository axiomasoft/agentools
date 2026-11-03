<?php
declare(strict_types=1);
namespace AgenToolsPlatform\ModelAdapter\Tests\Cache;
use AgenToolsPlatform\Core\Usage\{UsageEvent,UsageFold,UsagePort};
final class Sink implements UsagePort
{
    /** @var array<string,UsageEvent> */ public array $events=[];
    public bool $lost=false;
    public function __construct(private Fixture $f) {}
    public function append(UsageEvent $event): string
    {
        $this->f->store->assertIdle(); UsageFold::fold([...array_values($this->events),$event]); $this->events[$event->id->value]=$event;
        if ($this->lost) { throw new \RuntimeException('Lost local accounting ACK'); } return $event->acknowledgement();
    }
}
