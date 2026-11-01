<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Context\Memory;

enum CandidateType: string
{
    case Preference='preference';case Observation='observation';case Diagnosis='diagnosis';case Emotion='emotion';case Secret='secret';case ThirdParty='third_party';
    public function requiresManualAcceptance(): bool { return in_array($this,[self::Diagnosis,self::Emotion,self::Secret,self::ThirdParty],true); }
}
