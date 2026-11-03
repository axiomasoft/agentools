<?php

declare(strict_types=1);
namespace AgenToolsPlatform\Examples\SupportHost;

use AgenToolsPlatform\Core\Actions\{ActionAccess, ActionDefinition, ActionHandler, ActionRegistration, ActionRegistry, EffectClass};
use AgenToolsPlatform\Core\Common\CanonicalJson;

/** Code-owned definitions; current host authorization controls every projection. */
final readonly class Tools
{
    public ActionDefinition $read;
    public ActionDefinition $update;
    public ActionRegistry $registry;
    public function __construct(CanonicalJson $json)
    {
        $schema = '{"type":"object","properties":{"ticket":{"type":"string"}},"required":["ticket"],"additionalProperties":false}';
        $this->read = new ActionDefinition('support.ticket.read', '1', 'support.read', $schema, '{"type":"object"}',
            EffectClass::Read, ['support.read'], false, 1000, 1, 0, 3000, 'native_key', null,
            'tenant-region', 'technical-ref-only', 'minimal-view', ['outcome'], null, $json);
        $this->update = new ActionDefinition('support.ticket.update', '1', 'support.update', $schema, '{"type":"object"}',
            EffectClass::Write, ['support.update'], true, 1000, 1, 0, 3000, 'native_key', null,
            'tenant-region', 'technical-ref-only', 'minimal-view', ['outcome'], null, $json);
        $read = new class implements ActionHandler { public function handlerId(): string { return 'support.read'; } };
        $update = new class implements ActionHandler { public function handlerId(): string { return 'support.update'; } };
        $this->registry = new ActionRegistry([new ActionRegistration($this->read, $read), new ActionRegistration($this->update, $update)]);
    }
    /**
     * @param list<string> $profile
     * @param list<string> $available
     */
    public function access(array $profile, array $available, Host $host): ActionAccess
    {
        $enabled = $host->allowed && !$host->emergency ? [ActionAccess::key($this->read->ref), ActionAccess::key($this->update->ref)] : [];
        return new ActionAccess($profile, $profile, $enabled, $profile, $available, ['support.read', 'support.update']);
    }
}
