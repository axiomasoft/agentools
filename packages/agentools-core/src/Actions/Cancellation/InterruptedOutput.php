<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Actions\Cancellation;

use AgenToolsPlatform\Core\Common\Ref;

/** Transport attestation. Null acknowledgement remains unknown; generated output is not delivered output. */
final readonly class InterruptedOutput
{
    public function __construct(public Ref $output, public string $channel, public string $unit,
        public ?int $acknowledgedUnits, public ?int $generatedUnits, public ?Ref $receipt)
    {
        if ($receipt!==null) { $output->tenant->assertSame($receipt->tenant); }
        if (!in_array($channel,['stream','tts'],true) || !in_array($unit,['chunks','bytes','audio_frames'],true)
            || ($acknowledgedUnits!==null && ($acknowledgedUnits<0 || $acknowledgedUnits>9007199254740991 || $receipt===null))
            || ($generatedUnits!==null && ($generatedUnits<0 || $generatedUnits>9007199254740991))
            || ($acknowledgedUnits!==null && $generatedUnits!==null && $acknowledgedUnits>$generatedUnits)) {
            throw new \InvalidArgumentException('Invalid acknowledged output portion');
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['output_ref'=>$this->output->value,'channel'=>$this->channel,'state'=>'interrupted',
            'unit'=>$this->unit,'acknowledged_units'=>$this->acknowledgedUnits,'generated_units'=>$this->generatedUnits,
            'acknowledgement'=>$this->acknowledgedUnits===null?'unknown':'known','receipt_ref'=>$this->receipt?->value];
    }
}
