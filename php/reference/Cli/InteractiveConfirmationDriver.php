<?php

declare(strict_types=1);

namespace AgenTools\Reference\Cli;

/** Maps scripted or interactive decisions to coordinator approvals (no gate policy). */
final class InteractiveConfirmationDriver
{
    /** @var list<ConfirmationChoice> */
    private array $scriptedChoices = [];

    private int $scriptIndex = 0;

    /** @param list<ConfirmationChoice> $choices */
    public function loadScript(array $choices): void
    {
        $this->scriptedChoices = $choices;
        $this->scriptIndex = 0;
    }

    /** @param array<string, mixed> $pendingArguments */
    public function nextChoice(array $pendingArguments): ConfirmationChoice
    {
        if ($this->scriptIndex < count($this->scriptedChoices)) {
            $choice = $this->scriptedChoices[$this->scriptIndex++];
            if ($choice->arguments === []) {
                return new ConfirmationChoice($choice->decision, $pendingArguments);
            }

            return $choice;
        }

        return new ConfirmationChoice('approve', $pendingArguments);
    }
}
