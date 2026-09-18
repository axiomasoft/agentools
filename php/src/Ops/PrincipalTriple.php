<?php

declare(strict_types=1);

namespace AgenTools\Ops;

use AgenTools\Action\Actor;

/** Validates and builds the distinguishable principal triple (SEC-13..SEC-16). */
final class PrincipalTriple
{
    /**
     * @param array<string, mixed> $principals
     * @param array<string, mixed> $authorization
     */
    public function validate(array $principals, array $authorization): ?string
    {
        $initiator = $this->principalId($principals, 'initiator');
        $agent = $this->principalId($principals, 'agent');
        if ($initiator === null || $agent === null) {
            return 'missing_principal';
        }

        if ($initiator === $agent) {
            return 'merged_user_and_agent';
        }

        if ($this->looksLikeRuntimeAccount($initiator)) {
            return 'runtime_account_as_end_user';
        }

        $scope = $authorization['resource'] ?? null;
        if (!is_string($scope) || $scope === '') {
            return 'missing_authorization_scope';
        }

        return null;
    }

    /**
     * @param array<string, mixed> $principals
     * @param array<string, mixed> $authorization
     *
     * @return array{actor: Actor, payload: array<string, string>}
     */
    public function buildInvocationRecord(array $principals, array $authorization): array
    {
        $failure = $this->validate($principals, $authorization);
        if ($failure !== null) {
            throw new \InvalidArgumentException(sprintf('Principal triple is invalid: %s.', $failure));
        }

        $agentId = $this->principalId($principals, 'agent');
        $initiatorId = $this->principalId($principals, 'initiator');
        if ($agentId === null || $initiatorId === null) {
            throw new \InvalidArgumentException('Principal triple is invalid.');
        }

        $scope = $authorization['resource'] ?? null;
        if (!is_string($scope) || $scope === '') {
            throw new \InvalidArgumentException('Authorization scope is required.');
        }

        return [
            'actor' => new Actor('agent', $agentId),
            'payload' => [
                'end_user_id' => $initiatorId,
                'authorization_scope' => $scope,
            ],
        ];
    }

    /** SEC-14; the agent principal lives in actor.type = agent. */
    public function assertAgentActor(Actor $actor): void
    {
        if ($actor->type !== 'agent') {
            throw new \InvalidArgumentException('The agent principal must be carried by actor.type = agent.');
        }
    }

    /**
     * @param array<string, mixed> $principals
     */
    private function principalId(array $principals, string $role): ?string
    {
        $entry = $principals[$role] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        $id = $entry['id'] ?? null;

        return is_string($id) && $id !== '' ? $id : null;
    }

    private function looksLikeRuntimeAccount(string $initiatorId): bool
    {
        return str_starts_with($initiatorId, 'agent-') || str_starts_with($initiatorId, 'runtime-');
    }
}
