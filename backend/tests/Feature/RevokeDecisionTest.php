<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Db\ShardResolver;
use App\Http\Controllers\Admin\LicenseController;
use App\Models\License;
use App\Services\FeatureService;
use App\Services\LicenseLedgerService;
use App\Services\QuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Tests\TestCase;

/**
 * Locks spec/21-app/48-quota-restore-on-revoke.md §1 (eligibility) and
 * §5 (observability) against the Admin revoke path. Exercises the
 * private `applyRestore` decision function directly with a fake
 * QuotaService so all four branches are pinned without booting the
 * full HTTP + shard fixture stack:
 *
 *   AC-QREST-002: IssuerActorType=Admin returns skip=AdminIssued and
 *                 never touches QuotaService.
 *   AC-QREST-004: Reseller-issued row with ResellerQuotaLedgerId=NULL
 *                 (data anomaly) emits `quota.restore.missing_ledger_link`
 *                 at Error level and returns skip=AlreadyRestored so
 *                 revoke still succeeds per §5.3.
 *   AC-QREST-003: QuotaService's ClosedPeriod skip is echoed verbatim
 *                 on the decision envelope.
 *   AC-QREST-001: Happy path returns QuotaRestored=true and forwards
 *                 the funding tuple to QuotaService::restoreForLicense.
 */
final class RevokeDecisionTest extends TestCase
{
    #[Test]
    public function admin_issued_short_circuits_before_quota_service(): void
    {
        $spy = new FakeQuotaService();
        $decision = $this->invokeApplyRestore(
            quotaService: $spy,
            license: $this->license(issuerActor: 'Admin', ledgerId: null),
        );
        $this->assertFalse($decision['QuotaRestored']);
        $this->assertSame('AdminIssued', $decision['RestoreSkippedReason']);
        $this->assertSame(0, $spy->calls, 'QuotaService must not be called for admin-issued.');
    }

    #[Test]
    public function reseller_issued_with_null_ledger_link_logs_error_and_skips(): void
    {
        Log::spy();
        $spy = new FakeQuotaService();
        $decision = $this->invokeApplyRestore(
            quotaService: $spy,
            license: $this->license(issuerActor: 'Reseller', ledgerId: null),
        );
        $this->assertFalse($decision['QuotaRestored']);
        $this->assertSame('AlreadyRestored', $decision['RestoreSkippedReason']);
        $this->assertSame(0, $spy->calls);
        Log::shouldHaveReceived('error')->with('quota.restore.missing_ledger_link', \Mockery::any())->once();
    }

    #[Test]
    public function closed_period_from_service_is_forwarded(): void
    {
        $spy = new FakeQuotaService(response: [
            'QuotaRestored' => false, 'RestoreSkippedReason' => 'ClosedPeriod', 'LedgerId' => null,
        ]);
        $decision = $this->invokeApplyRestore(
            quotaService: $spy,
            license: $this->license(issuerActor: 'Reseller', ledgerId: 555),
        );
        $this->assertFalse($decision['QuotaRestored']);
        $this->assertSame('ClosedPeriod', $decision['RestoreSkippedReason']);
        $this->assertSame(1, $spy->calls);
    }

    #[Test]
    public function happy_path_forwards_funding_tuple_and_reports_restored(): void
    {
        $spy = new FakeQuotaService(response: [
            'QuotaRestored' => true, 'RestoreSkippedReason' => '', 'LedgerId' => 900,
        ]);
        $decision = $this->invokeApplyRestore(
            quotaService: $spy,
            license: $this->license(issuerActor: 'Reseller', ledgerId: 555),
        );
        $this->assertTrue($decision['QuotaRestored']);
        $this->assertSame('', $decision['RestoreSkippedReason']);
        $this->assertSame(42, $spy->lastArgs['resellerId']);
        $this->assertSame(4, $spy->lastArgs['licenseCategoryId']);
        $this->assertSame('Standard', $spy->lastArgs['tierName']);
        $this->assertSame(1001, $spy->lastArgs['licenseId']);
        $this->assertSame(555, $spy->lastArgs['resellerQuotaLedgerId']);
    }

    /**
     * @param 'Admin'|'Reseller' $issuerActor
     */
    private function license(string $issuerActor, ?int $ledgerId): License
    {
        $row = new License();
        $row->LicenseId = 1001;
        $row->LicenseKey = 'ABC-0001';
        $row->ResellerId = 42;
        $row->LicenseCategoryId = 4;
        $row->TierName = 'Standard';
        $row->IssuerActorType = $issuerActor;
        $row->ResellerQuotaLedgerId = $ledgerId;
        $row->Status = 'Active';
        $row->Version = 1;

        return $row;
    }

    private function invokeApplyRestore(FakeQuotaService $quotaService, License $license): array
    {
        $controller = new LicenseController(
            shardResolver: $this->app->make(ShardResolver::class),
            quotaService: $quotaService,
            ledgerService: $this->app->make(LicenseLedgerService::class),
            featureService: $this->app->make(FeatureService::class),
        );
        $request = Request::create('/Api/Admin/Licenses/ABC-0001', 'DELETE');
        $request->setUserResolver(fn () => new FakeAuthUser(7));
        $ref = new ReflectionClass($controller);
        $method = $ref->getMethod('applyRestore');
        $method->setAccessible(true);
        /** @var array{QuotaRestored:bool, RestoreSkippedReason:string} $out */
        $out = $method->invoke($controller, $request, $license);

        return $out;
    }
}

final class FakeQuotaService extends QuotaService
{
    public int $calls = 0;
    /** @var array<string, mixed> */
    public array $lastArgs = [];

    /**
     * @param array{QuotaRestored:bool, RestoreSkippedReason:string, LedgerId:?int} $response
     */
    public function __construct(private readonly array $response = [
        'QuotaRestored' => true, 'RestoreSkippedReason' => '', 'LedgerId' => 1,
    ]) {
    }

    public function restoreForLicense(
        int $resellerId,
        int $licenseCategoryId,
        string $tierName,
        int $licenseId,
        int $resellerQuotaLedgerId,
        string $requestId,
        int $actorUserId,
        ?\Illuminate\Support\Carbon $now = null,
    ): array {
        $this->calls++;
        $this->lastArgs = compact('resellerId', 'licenseCategoryId', 'tierName', 'licenseId', 'resellerQuotaLedgerId', 'requestId', 'actorUserId');

        return $this->response;
    }
}

final class FakeAuthUser implements \Illuminate\Contracts\Auth\Authenticatable
{
    public function __construct(private readonly int $id)
    {
    }

    public function getAuthIdentifierName(): string
    {
        return 'UserId';
    }

    public function getAuthIdentifier(): int
    {
        return $this->id;
    }

    public function getAuthPasswordName(): string
    {
        return 'Password';
    }

    public function getAuthPassword(): string
    {
        return '';
    }

    public function getRememberToken(): string
    {
        return '';
    }

    public function setRememberToken($value): void
    {
    }

    public function getRememberTokenName(): string
    {
        return '';
    }
}
