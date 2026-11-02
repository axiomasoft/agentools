<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Examples\Knowledge;

use AgenToolsPlatform\Examples\ReadJourney\SupportJourney;
use AgenToolsPlatform\Knowledge\{Decision,Material,MaterialAccessPolicy,Scope};
use AgenToolsPlatform\Knowledge\Retrieval\SourceAvailability;

/** Scripted Library/Files policy backed by current host SQL, never by a cached license pin. */
final class Owner implements MaterialAccessPolicy,SourceAvailability
{
    public Decision $sourceDecision=Decision::Allowed;
    public function __construct(private readonly SupportJourney $host, private readonly string $audience) {}
    public function current(Scope $scope): Decision
    {
        $h=$this->host;$h->idle();$h->context->assertCurrent($h->host);
        if ($scope->tenant!==$h->tenant->value || $scope->subject!==$h->context->identity->subjectRef?->value
            || $scope->purpose!==$h->context->purpose || $scope->audience!==$this->audience
            || !in_array('knowledge.read',$scope->scopes,true)) { return Decision::Denied; }
        $row=$h->db->row('SELECT context_allowed,disclosure_allowed,emergency FROM '.$h->db->table('demo_permissions').' WHERE tenant_id=:tenant',['tenant'=>$h->tenant->value]);
        return $row!==null && $row['context_allowed']===true && $row['disclosure_allowed']===true && $row['emergency']===false ? Decision::Allowed : Decision::Denied;
    }
    public function check(Scope $scope, Material $material, string $operation='source'): Decision
    {
        $current=$this->current($scope);
        return $current===Decision::Allowed && $scope->matches($material) ? $this->sourceDecision : Decision::Denied;
    }
}
