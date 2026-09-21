<?php

declare(strict_types=1);

namespace AgenTools\Confirmation;

final readonly class ApprovalRequirement
{
    public const string MODE_NONE = 'none';
    public const string MODE_QUORUM = 'quorum';

    public function __construct(
        public string $mode,
        public int $quorum,
        public bool $separateFromProposer,
    ) {
        if (!in_array($mode, [self::MODE_NONE, self::MODE_QUORUM], true) || $quorum < 1) {
            throw new \InvalidArgumentException('Approval requirement needs a known mode and quorum of at least one.');
        }
        if ($mode === self::MODE_NONE && ($quorum !== 1 || $separateFromProposer)) {
            throw new \InvalidArgumentException('No-approval mode cannot select quorum or separation.');
        }
    }

    public static function none(): self
    {
        return new self(self::MODE_NONE, 1, false);
    }

    public function equals(self $other): bool
    {
        return $this->mode === $other->mode
            && $this->quorum === $other->quorum
            && $this->separateFromProposer === $other->separateFromProposer;
    }
}
