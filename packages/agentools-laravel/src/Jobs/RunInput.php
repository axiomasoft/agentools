<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Jobs;

use AgenToolsPlatform\Laravel\Managed\{Entry, Executor, Input};
use Illuminate\Contracts\Queue\ShouldQueue;

/** Queue only a host handle; retry always rehydrates CURRENT authority and durable identity. */
final readonly class RunInput implements ShouldQueue
{
    public function __construct(public string $inputRef) { new Input($inputRef, Entry::Background); }
    public function handle(Executor $executor): void { $executor->execute(new Input($this->inputRef, Entry::Background)); }
}
