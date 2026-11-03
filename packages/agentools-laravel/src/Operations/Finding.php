<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Laravel\Operations;

/** Closed metadata shape prevents probe exception messages/content/secrets leaking into diagnostics. */
final readonly class Finding
{
    public function __construct(public string $check, public string $status, public string $code,
        public ?string $version = null)
    {
        if (preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D',$check)!==1
            || !in_array($status,['ready','not_ready','unknown'],true)
            || preg_match('/\A[a-z][a-z0-9_]{0,63}\z/D',$code)!==1
            || ($version!==null && preg_match('/\A[a-zA-Z0-9.+_-]{1,80}\z/D',$version)!==1)) {
            throw new \InvalidArgumentException('Redacted finding required');
        }
    }
    /** @return array<string,string|null> */
    public function metadata(): array { return ['check'=>$this->check,'status'=>$this->status,'code'=>$this->code,'version'=>$this->version]; }
}
