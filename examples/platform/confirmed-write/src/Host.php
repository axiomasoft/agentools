<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\ConfirmedWrite;

use AgenTools\Contracts\Clock;
use AgenToolsPlatform\Core\Actions\ActionSubmission;
use AgenToolsPlatform\Core\Actions\Confirmation\{ConfirmationAuthority, Decision, Preview};
use AgenToolsPlatform\Core\Common\{ContractViolation, ErrorCode, Ref, SessionId, TenantId};
use AgenToolsPlatform\Core\Security\{HostAuthorization, HostIdentity};

/** Synthetic host ports only. Consent events are populated by an authenticated host UI/voice boundary. */
final class Host implements HostAuthorization,ConfirmationAuthority,Clock
{
    public bool $denied=false;
    public string $revision='policy-1';
    public ?Decision $consent=null;
    public ?\DateTimeImmutable $instant=null;
    public function __construct(public TenantId $tenant,public string $actor='actor') {}
    public function authorize(SessionId $session,string $purpose): HostIdentity
    {
        $this->tenant->assertSame($session->tenant);
        if ($this->denied || $purpose!=='document.write') { throw new ContractViolation(ErrorCode::AccessDenied); }
        return new HostIdentity($this->tenant,new Ref($this->tenant,$this->actor),null,new Ref($this->tenant,'host:write-authorization'),$this->revision);
    }
    public function presentation(ActionSubmission $submission): string
    {
        return json_encode(['summary'=>'Write the selected document','changes'=>[json_decode($submission->inputJson,false,512,JSON_THROW_ON_ERROR)]],JSON_THROW_ON_ERROR);
    }
    public function decision(Preview $preview): ?Decision { return $this->consent; }
    public function now(): \DateTimeImmutable { return $this->instant ?? new \DateTimeImmutable('now',new \DateTimeZone('UTC')); }
}
