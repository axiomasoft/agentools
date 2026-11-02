<?php

declare(strict_types=1);

namespace AgenToolsPlatform\Core\Budget;

use AgenToolsPlatform\Core\Common\{CanonicalHasher, Ref, UtcInstant};
use AgenToolsPlatform\Core\Usage\{Certainty, UsageEvent, Values};

/** Only invokes the existing external owner port. There is no provider/Action execution or local allowance. */
final readonly class BudgetBoundary
{
    public function __construct(private BudgetPort $owner, private BudgetGuard $guard, private CanonicalHasher $hasher) {}
    public function admit(AdmissionScope $scope, UtcInstant $now): Admission
    {
        $request = $scope->request($this->hasher); $this->guard->assertCurrent($request,$now);
        if (self::compare($now,$scope->deadline) >= 0) { return new \AgenToolsPlatform\Core\Budget\Admission('denied',reason:'DEADLINE_EXPIRED'); }
        try { $reply = $this->owner->status($request); } catch (AdmissionConflict $error) { throw $error; } catch (\Throwable) { return self::unknown(); }
        if ($reply->status === 'not_found') {
            $this->guard->assertCurrent($request,$now);
            try { $reply = $this->owner->admit($request); } catch (AdmissionConflict $error) { throw $error; } catch (\Throwable) { return self::unknown(); }
        }
        $this->assertBinding($request,$reply); $this->guard->assertCurrent($request,$now);
        if ($reply->status === 'admitted' && ($reply->expiresAt === null || self::compare($now,$reply->expiresAt) >= 0)) {
            return new \AgenToolsPlatform\Core\Budget\Admission('denied',reason:'ADMISSION_EXPIRED');
        }
        return $reply;
    }
    public function status(AdmissionScope $scope, UtcInstant $now): Admission
    {
        $request = $scope->request($this->hasher); $this->guard->assertCurrent($request,$now);
        try { $reply = $this->owner->status($request); } catch (AdmissionConflict $error) { throw $error; } catch (\Throwable) { return self::unknown(); }
        $this->assertBinding($request,$reply); $this->guard->assertCurrent($request,$now);
        return $reply->status === 'admitted' && ($reply->expiresAt === null || self::compare($now,$reply->expiresAt) >= 0)
            ? new \AgenToolsPlatform\Core\Budget\Admission('denied',reason:'ADMISSION_EXPIRED') : $reply;
    }
    public function settle(ReservationCheckpoint $saved, UsageEvent $event, UtcInstant $now): ReservationCheckpoint
    {
        $this->guard->assertCurrent($saved->request,$now); $saved->reservation->tenant->assertSame($event->id->tenant);
        if ($saved->status !== ReservationStatus::Active || $saved->exposure !== Exposure::Known || $event->certainty !== Certainty::Final
            || ($saved->request->content['invocation_ref'] ?? null) !== $event->invocation->value
            || ($saved->request->content['business_operation_ref'] ?? null) !== $event->toArray()['business_operation_ref']) {
            throw new \LogicException('Known final usage and active reservation required; otherwise owner status lookup');
        }
        try { $this->owner->settle($saved->reservation,$event->id); } catch (\Throwable) { return $saved->next(ReservationStatus::Unknown); }
        return $saved->next(ReservationStatus::Settled);
    }
    public function release(ReservationCheckpoint $saved, UtcInstant $now): ReservationCheckpoint
    {
        $this->guard->assertCurrent($saved->request,$now);
        if ($saved->status !== ReservationStatus::Active || $saved->exposure !== Exposure::NotSent || $saved->notSentEvidence === null) {
            throw new \LogicException('Unknown expense cannot be released; reconcile same operation key');
        }
        try { $this->owner->release($saved->reservation,'proven_not_sent:'.$saved->notSentEvidence->value); }
        catch (\Throwable) { return $saved->next(ReservationStatus::Unknown); }
        return $saved->next(ReservationStatus::Released);
    }
    public function renew(ReservationCheckpoint $saved, UtcInstant $deadline, UtcInstant $now): Admission
    {
        $this->guard->assertCurrent($saved->request,$now);
        if ($saved->status !== ReservationStatus::Active || self::compare($now,$deadline) >= 0) { return new \AgenToolsPlatform\Core\Budget\Admission('unknown',reason:'OWNER_STATUS_LOOKUP_REQUIRED'); }
        try { $reply = $this->owner->renew($saved->reservation,$deadline); } catch (AdmissionConflict $error) { throw $error; } catch (\Throwable) { return self::unknown(); }
        $this->assertBinding($saved->request,$reply);
        if ($reply->reservationRef !== null) { $saved->reservation->assertSame($reply->reservationRef); }
        $this->guard->assertCurrent($saved->request,$now);
        return $reply->status === 'admitted' && ($reply->expiresAt === null || self::compare($now,$reply->expiresAt) >= 0)
            ? new \AgenToolsPlatform\Core\Budget\Admission('denied',reason:'ADMISSION_EXPIRED') : $reply;
    }
    private function assertBinding(AdmissionRequest $request, Admission $reply): void
    {
        foreach ([$reply->admissionRef,$reply->reservationRef] as $ref) { if ($ref !== null) { $request->operationKey->tenant->assertSame($ref->tenant); } }
    }
    private static function unknown(): Admission { return new \AgenToolsPlatform\Core\Budget\Admission('unknown',reason:'OWNER_REPLY_LOST'); }
    private static function compare(UtcInstant $a, UtcInstant $b): int
    { return Values::compare($a,$b); }
}
