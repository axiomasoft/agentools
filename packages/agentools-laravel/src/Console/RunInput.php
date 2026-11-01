<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Laravel\Console;

use AgenToolsPlatform\Laravel\Managed\{Entry, Executor, Input};
use Illuminate\Console\Command;

final class RunInput extends Command
{
    protected $signature = 'agentools:run {input_ref : Trusted host input handle}';
    protected $description = 'Advance one admitted AgenTools input; no implicit identity or owner effects';

    public function handle(Executor $executor): int
    {
        $ref = $this->argument('input_ref');
        if (!is_string($ref)) { return self::INVALID; }
        $receipt = $executor->execute(new Input($ref, Entry::Background));
        $this->line(json_encode(['delivery_id' => $receipt->id->toArray(),
            'acknowledgement' => $receipt->acknowledgement?->toArray()], JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
