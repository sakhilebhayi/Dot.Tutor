<?php

namespace App\Domain\Integration;

use App\Domain\Academic\Contracts\AcademicAudit;
use App\Domain\Academic\Contracts\AgeBandResolver;
use App\Domain\Integration\Adapters\Academic\SafetyAcademicAudit;
use App\Domain\Integration\Adapters\Academic\SafetyAgeBandResolver;
use App\Domain\Integration\Adapters\Learning\AcademicScopeLookupAdapter;
use App\Domain\Integration\Adapters\Learning\DirectoryTutorStanding;
use App\Domain\Integration\Adapters\Learning\MoneyCheckoutGateway;
use App\Domain\Integration\Adapters\Learning\MoneyEntitlementLookup;
use App\Domain\Integration\Adapters\Learning\SafetyAuditTrail;
use App\Domain\Integration\Adapters\Learning\SafetyContentReportSink;
use App\Domain\Integration\Adapters\Learning\SafetySafeguardingCheck;
use App\Domain\Integration\Adapters\Marketplace\AcademicScopeAdapter;
use App\Domain\Integration\Adapters\Marketplace\LanguageProfileAdapter;
use App\Domain\Integration\Adapters\Marketplace\MoneyCheckoutPort;
use App\Domain\Integration\Adapters\Marketplace\SafetyAuditPort;
use App\Domain\Integration\Adapters\Marketplace\SafetyGuardianApprovals;
use App\Domain\Integration\Adapters\Marketplace\SafetyLearnerAge;
use App\Domain\Integration\Adapters\Marketplace\SafetyReportSinkPort;
use App\Domain\Integration\Adapters\Marketplace\SafetySafeguardingPort;
use App\Domain\Integration\Adapters\Money\GuardianPurchaseApprovals;
use App\Domain\Integration\Adapters\Money\SafetyBuyerAge;
use App\Domain\Integration\Adapters\Money\SafetyMoneyAudit;
use App\Domain\Integration\Adapters\Safety\LearnerProfileBirthDates;
use App\Domain\Integration\Listeners\ApplyGuardianDecision;
use App\Domain\Integration\Listeners\FulfilPaidOrder;
use App\Domain\Integration\Listeners\OrderClosedUnpaid;
use App\Domain\Integration\Listeners\RaiseRefundDue;
use App\Domain\Integration\Listeners\RecordRefund;
use App\Domain\Integration\Purchasables\MarketplaceBookingPurchasable;
use App\Domain\Integration\Purchasables\PackagePurchasable;
use App\Domain\Integration\Purchasables\SeatPurchasable;
use App\Domain\Integration\Purchasables\SellablePurchasable;
use App\Domain\Learning\Contracts\Seams\AcademicScopeLookup;
use App\Domain\Learning\Contracts\Seams\AuditTrail;
use App\Domain\Learning\Contracts\Seams\CheckoutGateway;
use App\Domain\Learning\Contracts\Seams\ContentReportSink;
use App\Domain\Learning\Contracts\Seams\EntitlementLookup;
use App\Domain\Learning\Contracts\Seams\SafeguardingCheck;
use App\Domain\Learning\Contracts\Seams\TutorStanding;
use App\Domain\Learning\Models\Bundle;
use App\Domain\Learning\Models\Course;
use App\Domain\Learning\Models\LibraryResource;
use App\Domain\Marketplace\Contracts\AcademicScopePort;
use App\Domain\Marketplace\Contracts\AuditPort;
use App\Domain\Marketplace\Contracts\CheckoutPort;
use App\Domain\Marketplace\Contracts\GuardianApprovalsPort;
use App\Domain\Marketplace\Contracts\LanguageProfilePort;
use App\Domain\Marketplace\Contracts\LearnerAgePort;
use App\Domain\Marketplace\Contracts\ReportSinkPort;
use App\Domain\Marketplace\Contracts\SafeguardingPort;
use App\Domain\Marketplace\Models\TutorPackagePurchase;
use App\Domain\Marketplace\Models\TutorSessionEvent;
use App\Domain\Marketplace\Models\TutorSessionParticipant;
use App\Domain\Money\Contracts\BuyerAge;
use App\Domain\Money\Contracts\MoneyAudit;
use App\Domain\Money\Contracts\PurchaseApprovals;
use App\Domain\Money\Events\OrderPaid;
use App\Domain\Money\Events\OrderRefunded;
use App\Domain\Money\Models\Order;
use App\Domain\Money\Purchasables;
use App\Domain\Safety\Contracts\BirthDateSource;
use App\Domain\Safety\Models\GuardianApproval;
use App\Models\TutorSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * R-47 integration (wave 2, registered LAST in bootstrap/providers.php). Wave-1 domains depend on their own ports
 * with honest null defaults (bindIf); this provider binds every port to the owning domain's real implementation
 * through thin adapters, registers the marketplace and learning items as money purchasables, and wires the
 * callbacks between domains (guardian decisions, paid and refunded orders, refunds due). Plain bind() calls here
 * replace the bindIf defaults because this provider registers after every domain provider.
 *
 * The full table of bindings and callbacks, and what stays null and why, is docs/R47_INTEGRATION.md.
 */
class TutorIntegrationServiceProvider extends ServiceProvider
{
    /** @var array<class-string, class-string> port => adapter */
    public const BINDINGS = [
        // academic <- safety
        AgeBandResolver::class => SafetyAgeBandResolver::class,
        AcademicAudit::class => SafetyAcademicAudit::class,
        // safety <- academic
        BirthDateSource::class => LearnerProfileBirthDates::class,
        // money <- safety
        BuyerAge::class => SafetyBuyerAge::class,
        PurchaseApprovals::class => GuardianPurchaseApprovals::class,
        MoneyAudit::class => SafetyMoneyAudit::class,
        // learning <- academic, money, safety, marketplace
        AcademicScopeLookup::class => AcademicScopeLookupAdapter::class,
        EntitlementLookup::class => MoneyEntitlementLookup::class,
        CheckoutGateway::class => MoneyCheckoutGateway::class,
        ContentReportSink::class => SafetyContentReportSink::class,
        SafeguardingCheck::class => SafetySafeguardingCheck::class,
        AuditTrail::class => SafetyAuditTrail::class,
        TutorStanding::class => DirectoryTutorStanding::class,
        // marketplace <- academic, safety, money
        AcademicScopePort::class => AcademicScopeAdapter::class,
        LanguageProfilePort::class => LanguageProfileAdapter::class,
        LearnerAgePort::class => SafetyLearnerAge::class,
        GuardianApprovalsPort::class => SafetyGuardianApprovals::class,
        SafeguardingPort::class => SafetySafeguardingPort::class,
        CheckoutPort::class => MoneyCheckoutPort::class,
        ReportSinkPort::class => SafetyReportSinkPort::class,
        AuditPort::class => SafetyAuditPort::class,
    ];

    public function register(): void
    {
        foreach (self::BINDINGS as $port => $adapter) {
            $this->app->bind($port, $adapter);
        }
    }

    public function boot(): void
    {
        $this->registerPurchasables();

        Event::listen(OrderPaid::class, [FulfilPaidOrder::class, 'handle']);
        Event::listen(OrderRefunded::class, [RecordRefund::class, 'handle']);

        GuardianApproval::updated(fn (GuardianApproval $approval) => $this->app->make(ApplyGuardianDecision::class)->handle($approval));
        Order::updated(fn (Order $order) => $this->app->make(OrderClosedUnpaid::class)->handle($order));
        TutorSessionEvent::created(fn (TutorSessionEvent $event) => $this->app->make(RaiseRefundDue::class)->handle($event));
    }

    private function registerPurchasables(): void
    {
        $registry = $this->app->make(Purchasables::class);

        // Replaces money's own adapter for 'booking': marketplace v2 lessons carry their price snapshot and are
        // paid while awaiting payment; pre-R-47 lessons keep money's rule (MarketplaceBookingPurchasable extends it).
        $registry->register('booking', function (int $id) {
            $session = TutorSession::with('tutorProfile.user', 'subject')->find($id);

            return $session === null ? null : new MarketplaceBookingPurchasable($session);
        });
        $registry->register('seat', function (int $id) {
            $seat = TutorSessionParticipant::with('session.tutorProfile.user', 'session.subject')->find($id);

            return $seat === null ? null : new SeatPurchasable($seat);
        });
        $registry->register('package', function (int $id) {
            $purchase = TutorPackagePurchase::with('tutorProfile.user')->find($id);

            return $purchase === null ? null : new PackagePurchasable($purchase);
        });
        $registry->register('course', fn (int $id) => ($course = Course::query()->find($id)) ? new SellablePurchasable($course) : null);
        $registry->register('resource', fn (int $id) => ($resource = LibraryResource::query()->find($id)) ? new SellablePurchasable($resource) : null);
        $registry->register('course_bundle', fn (int $id) => ($bundle = Bundle::query()->find($id)) ? new SellablePurchasable($bundle) : null);
    }
}
