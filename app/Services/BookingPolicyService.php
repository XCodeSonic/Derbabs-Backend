<?php

namespace App\Services;

use App\Models\Setting;
use Carbon\Carbon;

class BookingPolicyService
{
    public const GROUP = 'booking';

    /**
     * Read a single setting value from the `booking` group.
     */
    private function get(string $key, $default = null)
    {
        $setting = Setting::where('group', self::GROUP)->where('key', $key)->first();
        if (! $setting) return $default;

        return match ($setting->type) {
            'boolean' => filter_var($setting->value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $setting->value,
            'float'   => (float) $setting->value,
            'array', 'json' => json_decode($setting->value, true),
            default   => $setting->value,
        };
    }

    public function minimumPax(): int
    {
        return (int) $this->get('minimum_pax', 1);
    }

    public function allowSameDay(): bool
    {
        return (bool) $this->get('allow_same_day_booking', false);
    }

    public function allowHoliday(): bool
    {
        return (bool) $this->get('allow_holiday_booking', false);
    }

    public function allowWeekend(): bool
    {
        return (bool) $this->get('allow_weekend_booking', true);
    }

    public function cancellationCutoffDays(): int
    {
        return (int) $this->get('cancellation_cutoff_days', 3);
    }

    public function depositPaymentDays(): int
    {
        return (int) $this->get('deposit_payment_days', 7);
    }

    public function depositAmount(): float
    {
        return (float) $this->get('deposit_amount', 0);
    }

    /**
     * ⭐ Deposit percentage of the total booking amount.
     * Default: 30%.
     *
     * When a value is stored in the `booking` group as `deposit_percentage`,
     * that value wins. Otherwise this falls back to 30%.
     */
    public function depositPercentage(): float
    {
        $value = $this->get('deposit_percentage', null);
        if ($value !== null && $value !== '') {
            return (float) $value;
        }
        return 30.0;
    }

    public function requireDeposit(): bool
    {
        return (bool) $this->get('require_deposit', false);
    }

    /**
     * Hours the customer has to respond to an admin reschedule proposal.
     * Default: 24 hours.
     */
    public function customerRescheduleResponseHours(): int
    {
        return (int) $this->get('customer_reschedule_response_hours', 24);
    }

    /**
     * Hours the admin has to respond to a customer reschedule request.
     * Default: 48 hours.
     */
    public function adminRescheduleResponseHours(): int
    {
        return (int) $this->get('admin_reschedule_response_hours', 48);
    }

    /**
     * Validate a proposed booking against the current policy.
     * Returns an array of human-readable error strings.
     * Empty array = OK.
     */
    public function validate(array $data): array
    {
        $errors = [];

        // ---- Guest count ----
        $guests = (int) ($data['guests_count'] ?? 0);
        if ($guests > 0 && $guests < $this->minimumPax()) {
            $errors[] = "Minimum of {$this->minimumPax()} guests is required to create a booking.";
        }

        // ---- Event date ----
        $rawDate = $data['event_date'] ?? null;
        $eventDate = null;

        if ($rawDate) {
            try {
                $eventDate = $rawDate instanceof \DateTimeInterface
                    ? Carbon::instance($rawDate)
                    : Carbon::parse($rawDate);
            } catch (\Throwable $e) {
                $errors[] = 'Invalid event date.';
                return $errors;
            }
        }

        if ($eventDate) {
            $today = now()->startOfDay();
            $eventDay = $eventDate->copy()->startOfDay();

            // Past dates
            if ($eventDay->lt($today)) {
                $errors[] = 'Event date cannot be in the past.';
            }
            // Same-day
            elseif (! $this->allowSameDay() && $eventDay->equalTo($today)) {
                $errors[] = 'Same-day booking is not allowed. Please choose a later date.';
            }

            // Weekend
            if (! $this->allowWeekend() && $eventDate->isWeekend()) {
                $errors[] = 'Weekend bookings are not allowed. Please choose a weekday.';
            }

            // Holiday
            if (! $this->allowHoliday() && $this->isHoliday($eventDate)) {
                $errors[] = 'Holiday bookings are not allowed. Please choose another date.';
            }
        }

        return $errors;
    }

    /**
     * Check whether the given date is flagged as a holiday
     * in the `booking_calendar` settings group.
     */
    private function isHoliday(Carbon $date): bool
    {
        $setting = Setting::where('group', 'booking_calendar')
            ->where('key', $date->toDateString())
            ->first();

        if (! $setting) return false;

        $value = json_decode($setting->value, true);
        return is_array($value) && ($value['is_holiday'] ?? false) === true;
    }

    /**
     * Return all policy values as a flat array.
     * Useful for exposing to the customer-facing frontend.
     */
    public function all(): array
    {
        return [
            'minimum_pax'                        => $this->minimumPax(),
            'allow_same_day_booking'             => $this->allowSameDay(),
            'allow_holiday_booking'              => $this->allowHoliday(),
            'allow_weekend_booking'              => $this->allowWeekend(),
            'cancellation_cutoff_days'           => $this->cancellationCutoffDays(),
            'deposit_payment_days'               => $this->depositPaymentDays(),
            'deposit_amount'                     => $this->depositAmount(),
            'require_deposit'                    => $this->requireDeposit(),
            'deposit_percentage'                 => $this->depositPercentage(),
            'customer_reschedule_response_hours' => $this->customerRescheduleResponseHours(),
            'admin_reschedule_response_hours'    => $this->adminRescheduleResponseHours(),
        ];
    }
}