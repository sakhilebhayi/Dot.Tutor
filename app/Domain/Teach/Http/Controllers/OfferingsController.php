<?php

namespace App\Domain\Teach\Http\Controllers;

use App\Domain\Marketplace\Enums\OfferingType;
use App\Domain\Marketplace\Enums\TeachingFormat;
use App\Domain\Marketplace\Models\TutorOffering;
use App\Domain\Marketplace\Services\OfferingService;
use App\Domain\Marketplace\Services\TutorPresenter;
use App\Domain\Money\Exceptions\CheckoutRefused;
use App\Domain\Money\Http\Controllers\TutorMoneyController;
use App\Domain\Money\Models\Coupon;
use App\Domain\Money\Money;
use App\Domain\Money\Services\CommissionRules;
use App\Domain\Money\Services\Coupons;
use App\Domain\Teach\Services\AcademicOptions;
use App\Domain\Teach\Support\TutorContext;
use App\Http\Controllers\Controller;
use App\Models\TutorProfile;
use App\Models\TutorSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Offerings and pricing (brief sections 7, 17 and 18): all eight offering types, packages and weekly series, with
 * the commission and what the tutor keeps shown before saving (money's CommissionRules, the same rule an order is
 * priced with), and the tutor's own discount codes (money coupons).
 */
class OfferingsController extends Controller
{
    /** What the price buys, per offering type, in plain words. */
    public const PRICE_LABELS = [
        'group' => 'Price per seat',
        'package' => 'Price for the whole package',
        'recurring_weekly' => 'Price per weekly lesson',
    ];

    public function index(Request $request, TutorPresenter $presenter, CommissionRules $rules): View
    {
        $profile = TutorContext::profile($request);
        $offerings = $profile->offerings()->with('subjects:id,name')->get();
        $booked = TutorSession::query()->where('tutor_profile_id', $profile->id)->whereNotNull('tutor_offering_id')
            ->where('status', 'completed')->selectRaw('tutor_offering_id, count(*) as n')->groupBy('tutor_offering_id')->pluck('n', 'tutor_offering_id');
        $coupons = Coupon::query()->where('owner_id', $request->user()->id)->orderByDesc('id')->get();

        return view('teach.offerings', [
            'profile' => $profile,
            'active' => $offerings->where('is_active', true)->values(),
            'archived' => $offerings->where('is_active', false)->values(),
            'presenter' => $presenter,
            'net' => fn (TutorOffering $o) => $rules->split(Money::of((int) $o->price_minor, $o->currency), 'lesson'),
            'coupons' => $coupons,
            'delivered' => $booked,
            'approved' => $profile->isApproved(),
        ]);
    }

    public function create(Request $request, AcademicOptions $options): View
    {
        $profile = TutorContext::profile($request);
        $type = OfferingType::tryFrom((string) $request->query('type')) ?? null;

        return view('teach.offering-form', $this->formData($profile, $options, null, $type));
    }

    public function edit(Request $request, TutorOffering $offering, AcademicOptions $options): View
    {
        $profile = TutorContext::profile($request);
        $this->own($profile, $offering);

        return view('teach.offering-form', $this->formData($profile, $options, $offering->load('subjects'), $offering->typeEnum()));
    }

    public function store(Request $request, OfferingService $service): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        $this->priceFromForm($request);
        $offering = $service->create($profile, $request->validate(OfferingService::rules()));

        return redirect()->route('teach.offerings')->with('success', "Offering saved: {$offering->title}.");
    }

    public function update(Request $request, TutorOffering $offering, OfferingService $service): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        $this->own($profile, $offering);
        $this->priceFromForm($request);
        // Unticked boxes and an emptied list send nothing: make that an explicit empty choice.
        $request->merge(['subject_ids' => $request->input('subject_ids', []), 'level_ids' => $request->input('level_ids', [])]);
        $data = $request->validate(collect(OfferingService::rules(partial: true))->except('type')->all());
        $service->update($offering, $data);

        return redirect()->route('teach.offerings')->with('success', 'Offering saved. Lessons already booked keep the price they were booked at.');
    }

    public function archive(Request $request, TutorOffering $offering, OfferingService $service): RedirectResponse
    {
        $this->own(TutorContext::profile($request), $offering);
        $service->archive($offering);

        return back()->with('success', 'Offering archived. Learners can no longer book it; existing bookings are unchanged.');
    }

    public function restore(Request $request, TutorOffering $offering, OfferingService $service): RedirectResponse
    {
        $this->own(TutorContext::profile($request), $offering);
        $service->update($offering, ['is_active' => true]);

        return back()->with('success', 'Offering is bookable again.');
    }

    /** The live preview: what a price leaves the tutor (same rule the order will be priced with). */
    public function preview(Request $request, CommissionRules $rules): JsonResponse
    {
        TutorContext::profile($request);
        $data = $request->validate([
            'price_minor' => ['required', 'integer', 'min:0', 'max:100000000'],
            'category' => ['nullable', 'in:lesson,course,resource,bundle'],
        ]);

        return response()->json(self::splitPayload($rules->split(Money::of((int) $data['price_minor']), $data['category'] ?? 'lesson')));
    }

    public function storeCoupon(Request $request, Coupons $coupons): RedirectResponse
    {
        $profile = TutorContext::profile($request);
        if (! $profile->isApproved()) {
            return back()->with('error', 'Discount codes open once your profile is approved.');
        }

        // Rands in the form, minor units for money.
        $request->merge([
            'amount_minor' => filled($request->input('amount')) ? Money::fromDecimal(str_replace(',', '.', (string) $request->input('amount')))->minor : null,
            'min_order_minor' => filled($request->input('min_order')) ? Money::fromDecimal(str_replace(',', '.', (string) $request->input('min_order')))->minor : null,
        ]);
        $data = TutorMoneyController::validateCoupon($request);

        try {
            $coupon = $coupons->create($request->user(), Coupon::FUNDED_BY_TUTOR, $data);
        } catch (CheckoutRefused $e) {
            throw ValidationException::withMessages(['code' => $e->getMessage()]);
        }

        return back()->with('success', "Code {$coupon->code} is live. It lowers your price, so commission is taken from the lower amount.");
    }

    public function deactivateCoupon(Request $request, Coupon $coupon): RedirectResponse
    {
        TutorContext::profile($request);
        abort_unless($coupon->owner_id === $request->user()->id, 404);
        $coupon->forceFill(['is_active' => false])->save();

        return back()->with('success', "Code {$coupon->code} switched off. Orders already placed keep their discount.");
    }

    /**
     * @param  array<string, mixed>  $split  CommissionRules::split()
     * @return array<string, mixed>
     */
    public static function splitPayload(array $split): array
    {
        return [
            'price_minor' => $split['price']->minor,
            'commission_percent' => $split['commission_bps'] / 100,
            'commission_minor' => $split['commission']->minor,
            'charges' => array_map(fn ($c) => ['label' => $c['label'], 'minor' => $c['amount']->minor], $split['charges']),
            'net_minor' => $split['net']->minor,
            'currency' => $split['price']->currency,
            'rule_version' => $split['rule_version'],
        ];
    }

    /** @return array<string, mixed> */
    private function formData(TutorProfile $profile, AcademicOptions $options, ?TutorOffering $offering, ?OfferingType $type): array
    {
        $teachable = $profile->subjects()->orderBy('name')->get(['subjects.id', 'subjects.name']);
        $rules = app(CommissionRules::class);
        $price = $offering?->price_minor ?? (int) ($profile->hourly_rate_minor ?? 0);

        return [
            'profile' => $profile,
            'offering' => $offering,
            'type' => $type,
            'types' => OfferingType::cases(),
            'formats' => TeachingFormat::cases(),
            'subjects' => $teachable->map(fn ($s) => ['value' => (int) $s->id, 'label' => $s->name])->all(),
            'levels' => $options->levels(),
            'split' => self::splitPayload($rules->split(Money::of($price, $offering?->currency ?? $profile->currency ?? null), 'lesson')),
            'priceLabels' => self::PRICE_LABELS,
            'limits' => [
                'min' => (int) config('marketplace.min_duration_minutes'),
                'max' => (int) config('marketplace.max_duration_minutes'),
                'capacity' => (int) config('marketplace.group_capacity_max'),
                'package' => (int) config('marketplace.package_lessons_max'),
                'weeks' => (int) config('marketplace.recurring_weeks_max'),
            ],
        ];
    }

    /** The form takes the price in rands ("250" or "250.50"); the domain takes integer minor units. */
    private function priceFromForm(Request $request): void
    {
        $raw = trim((string) $request->input('price', ''));
        if ($raw === '') {
            return;
        }
        if (! preg_match('/^\d{1,7}([.,]\d{1,2})?$/', $raw)) {
            throw ValidationException::withMessages(['price' => 'Write the price as a number, for example 250 or 250.50.']);
        }
        $request->merge(['price_minor' => Money::fromDecimal(str_replace(',', '.', $raw))->minor]);
    }

    private function own(TutorProfile $profile, TutorOffering $offering): void
    {
        abort_unless($offering->tutor_profile_id === $profile->id, 404);
    }
}
