<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Profiles\Skills;

/** Publication content is immutable. Current status/revoke comes from Policy, independently of this record. */
final readonly class PublishedSkill implements \JsonSerializable
{
    public function __construct(public SkillManifest $manifest, public Qualification $qualification)
    {
        if ($qualification->decision !== QualificationDecision::Qualified) {
            throw new \AgenToolsPlatform\Core\Profiles\Skills\SelectionFailure($qualification->decision === QualificationDecision::InsufficientEvidence ? 'QUALIFICATION_INSUFFICIENT' : 'QUALIFICATION_REJECTED');
        }
    }
    /**
     * @return array<string, mixed>
     */
    public function view(PublicationStatus $status = PublicationStatus::Published): array
    {
        return $this->manifest->toArray()+['publication_status'=>$status->value,'qualification'=>$this->qualification->toArray()];
    }
    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array { return $this->view(); }
}
