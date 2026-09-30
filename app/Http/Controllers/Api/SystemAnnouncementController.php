<?php

namespace App\Http\Controllers\Api;

use App\Models\AuditLog;
use App\Models\Setting;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SystemAnnouncementController extends Controller
{
    private const GROUP = 'system_announcements';

    public function index(Request $request)
    {
        $rows = Setting::where('group', self::GROUP)
            ->get()
            ->map(function (Setting $row) {
                $data = json_decode((string) $row->value, true) ?: [];
                $data['id'] = $row->key;
                return $data;
            })
            ->filter()
            ->sortByDesc('created_at')
            ->values();

        return $this->ok($rows);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        $id = 'ann_' . now()->format('YmdHis') . '_' . Str::random(6);

        $payload = array_merge($data, [
            'id'         => $id,
            'created_at' => now()->toIso8601String(),
            'created_by' => $request->user()?->user_id,
            'updated_at' => now()->toIso8601String(),
        ]);

        Setting::updateOrCreate(
            ['group' => self::GROUP, 'key' => $id],
            ['value' => json_encode($payload), 'type' => 'json']
        );

        try {
            AuditLog::log(
                'announcement_created',
                'settings',
                null,
                null,
                [
                    'title'        => $payload['title'],
                    'target_users' => $payload['target_users'] ?? 'all',
                    'priority'     => $payload['priority'] ?? 'normal',
                ]
            );
        } catch (\Throwable $e) {
            // silent
        }

        return $this->ok($payload, 'Announcement created successfully');
    }

    public function update(Request $request, string $id)
    {
        $setting = Setting::where('group', self::GROUP)->where('key', $id)->first();

        if (! $setting) {
            return $this->fail('Announcement not found', 404);
        }

        $existing = json_decode((string) $setting->value, true) ?: [];
        $data = $this->validatePayload($request);

        $payload = array_merge($existing, $data, [
            'updated_at' => now()->toIso8601String(),
            'updated_by' => $request->user()?->user_id,
        ]);

        $setting->update(['value' => json_encode($payload)]);

        try {
            AuditLog::log(
                'announcement_updated',
                'settings',
                null,
                $existing,
                $payload
            );
        } catch (\Throwable $e) {
            // silent
        }

        return $this->ok($payload, 'Announcement updated successfully');
    }

    public function destroy(Request $request, string $id)
    {
        $setting = Setting::where('group', self::GROUP)->where('key', $id)->first();

        if (! $setting) {
            return $this->fail('Announcement not found', 404);
        }

        $existing = json_decode((string) $setting->value, true) ?: [];
        $setting->delete();

        try {
            AuditLog::log(
                'announcement_deleted',
                'settings',
                null,
                $existing,
                null
            );
        } catch (\Throwable $e) {
            // silent
        }

        return $this->ok(null, 'Announcement deleted');
    }

    public function active(Request $request)
    {
        $now      = now();
        $userRole = $request->user()?->roles()->pluck('slug')->first();

        $rows = Setting::where('group', self::GROUP)
            ->get()
            ->map(fn (Setting $row) => json_decode((string) $row->value, true))
            ->filter()
            ->filter(function ($a) use ($now) {
                if (($a['is_active'] ?? true) !== true) {
                    return false;
                }
                if (! empty($a['start_date']) && Carbon::parse($a['start_date'])->isAfter($now)) {
                    return false;
                }
                if (! empty($a['end_date']) && Carbon::parse($a['end_date'])->endOfDay()->isBefore($now)) {
                    return false;
                }
                return true;
            })
            ->filter(function ($a) use ($userRole) {
                $target = $a['target_users'] ?? 'all';

                if ($target === 'all') {
                    return true;
                }
                if ($target === 'admins' && in_array($userRole, ['admin', 'super-admin'], true)) {
                    return true;
                }
                if ($target === 'staff' && in_array($userRole, ['cashier', 'inventory-manager', 'staff-manager', 'employee'], true)) {
                    return true;
                }
                if ($target === 'customers' && $userRole === 'customer') {
                    return true;
                }

                return $target === $userRole;
            })
            ->values();

        return $this->ok($rows);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'title'        => ['required', 'string', 'max:150'],
            'message'      => ['required', 'string', 'max:5000'],
            'start_date'   => ['nullable', 'date'],
            'end_date'     => ['nullable', 'date', 'after_or_equal:start_date'],
            'target_users' => ['nullable', 'in:all,customers,admins,staff'],
            'priority'     => ['nullable', 'in:low,normal,high,critical'],
            'is_active'    => ['nullable', 'boolean'],
        ]);
    }
}