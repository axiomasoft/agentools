<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

enum CandidateStatus: string
{
    case Proposed='proposed';case StoragePermitted='storage_permitted';case EvidenceChecked='evidence_checked';case HostAccepted='host_accepted';
    case Active='active';case Revalidated='revalidated';case Expired='expired';case Revoked='revoked';case Deleted='deleted';
}
