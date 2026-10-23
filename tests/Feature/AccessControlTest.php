<?php

namespace Tests\Feature;

use App\Modules\Authorization\Contracts\AccessControl;
use App\Modules\Authorization\Contracts\AccessDecision;
use App\Modules\Authorization\Contracts\AccessDenied;
use App\Modules\Authorization\Contracts\AccessRequest;
use App\Modules\Authorization\Contracts\TripRole;
use App\Modules\Authorization\Domain\AccessAuditLog;
use App\Modules\Authorization\Domain\PermissionMatrix;
use App\Modules\Authorization\Infrastructure\LogAccessAuditLog;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AccessControlTest extends TestCase
{
    private RecordingAccessAuditLog $audit;

    protected function setUp(): void
    {
        parent::setUp();

        $this->audit = new RecordingAccessAuditLog;
        $this->app->instance(AccessAuditLog::class, $this->audit);
    }

    #[Test]
    public function ts_plt_auth_001_01_unauthorized_access_to_another_travelers_sensitive_data_is_denied_and_audited(): void
    {
        $collaborator = $this->decide([TripRole::COLLABORATOR], 'TravelerDocument', 'read', 't-other');
        $owner = $this->decide([TripRole::OWNER], 'TravelerDocument', 'read', 't-other');
        $outsider = $this->decide([], 'TravelerDocument', 'read', 't-other');
        $foreignTrip = $this->decide([TripRole::OWNER], 'Traveler.sensitive_pii', 'read', 't-foreign');

        self::assertFalse($collaborator->allowed);
        self::assertSame(AccessDecision::MATRIX_DENY, $collaborator->reason);
        self::assertFalse($owner->allowed);
        self::assertSame([AccessDecision::DECISION_OPEN, 'OD-013'], [$owner->reason, $owner->decisionRef]);
        self::assertFalse($outsider->allowed);
        self::assertSame(AccessDecision::TRAVELER_NOT_IN_TRIP, $foreignTrip->reason);
        self::assertCount(4, $this->audit->records);
        self::assertSame([false, false, false, false], array_map(fn (array $record): bool => $record[1]->allowed, $this->audit->records));

        try {
            app(AccessControl::class)->ensure($this->request([TripRole::COLLABORATOR], 'TravelerDocument', 'read', 't-other'));
            self::fail('Erişim reddedilmeliydi.');
        } catch (AccessDenied $denied) {
            self::assertSame('Bu işlem için yetkiniz bulunmuyor.', $denied->getMessage());
            self::assertSame('AUTHORIZATION_DENIED', AccessDenied::MACHINE_CODE);
        }
        self::assertCount(5, $this->audit->records);
    }

    #[Test]
    public function ts_plt_auth_001_01_denial_is_written_to_the_access_audit_log_without_pii(): void
    {
        $this->app->instance(AccessAuditLog::class, new LogAccessAuditLog);
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$logged): void {
            $logged[] = $event;
        });

        $this->decide([TripRole::COLLABORATOR], 'TravelerDocument', 'read', 't-other');

        // The stack channel may dispatch one event per underlying channel.
        $logged = array_values(array_filter($logged, fn (MessageLogged $event): bool => $event->message === 'access.decision'));
        self::assertNotEmpty($logged);
        self::assertSame('warning', $logged[0]->level);
        self::assertSame('DENY', $logged[0]->context['decision']);
        self::assertSame('TravelerDocument', $logged[0]->context['resource']);
        self::assertSame('t-other', $logged[0]->context['traveler_id']);
    }

    #[Test]
    public function ts_plt_auth_001_02_owner_sees_only_the_traveler_fields_it_is_authorized_for(): void
    {
        $visible = [];
        foreach (['t-owner', 't-other'] as $travelerId) {
            foreach (['Traveler.basic', 'Traveler.sensitive_pii', 'TravelerDocument'] as $resource) {
                $visible[$travelerId][$resource] = $this->decide([TripRole::OWNER, TripRole::TRAVELER], $resource, 'read', $travelerId)->allowed;
            }
        }

        self::assertSame([
            't-owner' => ['Traveler.basic' => true, 'Traveler.sensitive_pii' => true, 'TravelerDocument' => true],
            't-other' => ['Traveler.basic' => true, 'Traveler.sensitive_pii' => false, 'TravelerDocument' => false],
        ], $visible);
        self::assertFalse($this->decide([TripRole::OWNER], 'Traveler.minor_sensitive_pii', 'read', 't-other')->allowed);
        self::assertFalse($this->decide([TripRole::OWNER, TripRole::TRAVELER], 'TravelerDocument', 'read', 't-owner', minor: true)->allowed);
    }

    #[Test]
    public function ts_plt_auth_001_03_traveler_can_open_own_document_data_only(): void
    {
        $own = $this->decide([TripRole::TRAVELER], 'TravelerDocument', 'read', 't-owner');
        $otherBasic = $this->decide([TripRole::TRAVELER], 'Traveler.basic', 'read', 't-other');

        self::assertTrue($own->allowed);
        self::assertSame(['POLICY-PII-VIEW-001', AccessDecision::POLICY_SATISFIED], [$own->policy, $own->reason]);
        self::assertTrue($this->decide([TripRole::TRAVELER], 'Traveler.sensitive_pii', 'read', 't-owner')->allowed);
        self::assertFalse($otherBasic->allowed);
        self::assertSame(AccessDecision::NOT_SELF, $otherBasic->reason);
        self::assertFalse($this->decide([TripRole::TRAVELER], 'TravelerDocument', 'read', 't-other')->allowed);
    }

    #[Test]
    public function fr_plt_auth_001_01_every_decision_requires_trip_scope_and_system_work_requires_a_workflow(): void
    {
        $noTrip = app(AccessControl::class)->authorize(new AccessRequest('user-1', [TripRole::OWNER], 'Trip', 'read'));

        self::assertSame(AccessDecision::SCOPE_MISSING, $noTrip->reason);
        self::assertTrue($this->decide([TripRole::OWNER], 'Trip', 'read')->allowed);
        self::assertFalse($this->decide([TripRole::SUPPORT_AGENT], 'Trip', 'read')->allowed);
        self::assertFalse($this->decide([TripRole::SYSTEM_WORKER], 'Trip', 'read')->allowed);
        self::assertTrue(app(AccessControl::class)->authorize(new AccessRequest('worker', [TripRole::SYSTEM_WORKER], 'Trip', 'read', 'trip-1', systemWorkflow: 'flight-search'))->allowed);
        self::assertSame(AccessDecision::SSOT_CONFLICT, $this->decide([TripRole::OWNER], 'Account', 'export')->reason);
        self::assertSame(AccessDecision::UNKNOWN_PERMISSION, $this->decide([TripRole::OWNER], 'Trip', 'delete')->reason);
    }

    #[Test]
    public function permission_matrix_mirrors_the_contract_registry_and_reports_its_known_conflicts(): void
    {
        [$matrix, $tests] = $this->parsePermissionsRegistry();

        self::assertSame($matrix, PermissionMatrix::DECISIONS);

        $conflicts = [];
        foreach ($tests as [$id, $key, $role, $expected]) {
            if (($matrix[$key][$role] ?? null) !== $expected) {
                $conflicts[] = $id;
            }
        }
        // Account export/delete: matrix (POLICY-ACCOUNT-SELF-001) and authorization_tests disagree in the SSOT.
        self::assertSame(['AUTH-TS-133', 'AUTH-TS-134', 'AUTH-TS-135', 'AUTH-TS-136', 'AUTH-TS-138', 'AUTH-TS-139', 'AUTH-TS-140', 'AUTH-TS-141', 'AUTH-TS-142', 'AUTH-TS-144'], $conflicts);
        self::assertCount(162, $tests);
    }

    /** @param list<TripRole> $roles */
    private function decide(array $roles, string $resource, string $action, ?string $travelerId = null, bool $minor = false): AccessDecision
    {
        return app(AccessControl::class)->authorize($this->request($roles, $resource, $action, $travelerId, $minor));
    }

    /** @param list<TripRole> $roles */
    private function request(array $roles, string $resource, string $action, ?string $travelerId = null, bool $minor = false): AccessRequest
    {
        return new AccessRequest(
            subjectId: 'user-owner',
            rolesInTrip: $roles,
            resource: $resource,
            action: $action,
            tripId: 'trip-1',
            travelerId: $travelerId,
            tripTravelerIds: ['t-owner', 't-other'],
            ownTravelerIds: ['t-owner'],
            travelerIsMinor: $minor,
        );
    }

    /** @return array{0: array<string, array<string, string>>, 1: list<array{0: string, 1: string, 2: string, 3: string}>} */
    private function parsePermissionsRegistry(): array
    {
        $matrix = [];
        $tests = [];
        $section = null;
        $current = [];
        foreach (file(base_path('contracts/registries/permissions.yaml'), FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^([a-z_]+):/', $line, $top) === 1) {
                $section = $top[1];

                continue;
            }
            if ($section === 'matrix') {
                if (preg_match('/^- resource: (\S+)$/', $line, $m) === 1) {
                    $current = ['resource' => $m[1]];
                } elseif (preg_match('/^  action: (\S+)$/', $line, $m) === 1) {
                    $current['action'] = $m[1];
                } elseif (preg_match('/^    ([a-z_]+):$/', $line, $m) === 1) {
                    $current['role'] = $m[1];
                } elseif (preg_match('/^      decision: (\S+)$/', $line, $m) === 1) {
                    $matrix[$current['resource'].':'.$current['action']][$current['role']] = $m[1];
                }
            } elseif ($section === 'authorization_tests') {
                if (preg_match('/^- id: (\S+)$/', $line, $m) === 1) {
                    $current = ['id' => $m[1]];
                } elseif (preg_match('/^  (resource|action|role|expected): (\S+)$/', $line, $m) === 1) {
                    $current[$m[1]] = $m[2];
                    if ($m[1] === 'expected') {
                        $tests[] = [$current['id'], $current['resource'].':'.$current['action'], $current['role'], $current['expected']];
                    }
                }
            }
        }

        return [$matrix, $tests];
    }
}

final class RecordingAccessAuditLog implements AccessAuditLog
{
    /** @var list<array{0: AccessRequest, 1: AccessDecision}> */
    public array $records = [];

    public function record(AccessRequest $request, AccessDecision $decision): void
    {
        $this->records[] = [$request, $decision];
    }
}
