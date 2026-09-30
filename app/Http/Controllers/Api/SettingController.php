<?php

namespace App\Http\Controllers\Api;

use App\Models\Setting;
use App\Models\User;
use App\Models\Role;
use App\Models\Person;
use App\Models\Employee;
use App\Models\AuditLog;
use App\Support\AuditLogCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\Rule;
use Illuminate\Support\Str;
use Illuminate\Support\Carbon;

class SettingController extends Controller
{
    private const SYSTEM_ACCOUNT_ROLES = [
        'super-admin',
        'admin',
        'cashier',
        'inventory-manager',
        'staff-manager',
    ];

    private const ADMIN_MANAGED_ROLES = [
        'cashier',
        'inventory-manager',
        'staff-manager',
    ];

    private const STAFF_GROUPS = [
        'staff_salary_types',
        'staff_employee_types',
        'staff_working_hours',
        'staff_overtime',
        'staff_undertime',
        'staff_late_attendance',
        'staff_holiday_rates',
        'staff_leave_types',
        'staff_schedule_rules',
        'staff_payroll_cutoff',
        'staff_government_contributions',
        'staff_employee_id',
    ];
    private const SYSTEM_GROUPS = [
        'system_business',
        'system_security_ext',
        'system_maintenance',
        'system_notifications',
        'system_backups',
    ];

    // ⭐ Allergens settings group — stores the master list of food allergens
    private const ALLERGENS_GROUP = 'food_allergens';
    // ============================================================
    // SETTINGS
    // ============================================================
    public function index(Request $request)
    {
        try {
            $settings = Setting::all()->groupBy('group');

            $formattedSettings = [];
            foreach ($settings as $group => $items) {
                $formattedSettings[$group] = [];
                foreach ($items as $item) {
                    $value = $this->decodeValue($item->value, $item->type);
                    $formattedSettings[$group][$item->key] = $value;
                }
            }

            return $this->ok($formattedSettings);
        } catch (\Exception $e) {
            return $this->fail('Failed to load settings: ' . $e->getMessage(), 500);
        }
    }

    public function getSection(Request $request, string $section)
    {
        try {
            $settings = Setting::where('group', $section)->get();

            $result = [];
            foreach ($settings as $item) {
                $result[$item->key] = $this->decodeValue($item->value, $item->type);
            }

            return $this->ok($result);
        } catch (\Exception $e) {
            return $this->fail('Failed to load section: ' . $e->getMessage(), 500);
        }
    }

    public function updateSection(Request $request, string $section)
    {
        try {
            $data = $request->input('data', $request->all());

            DB::transaction(function () use ($section, $data) {
                foreach ($data as $key => $value) {
                    $type = $this->detectType($value);
                    $encodedValue = $this->encodeValue($value, $type);

                    Setting::updateOrCreate(
                        ['group' => $section, 'key' => $key],
                        ['value' => $encodedValue, 'type' => $type]
                    );
                }
            });

            Cache::forget('settings_' . $section);
            $this->logSettingChange($section, $data);

            return $this->ok(null, $section . ' settings updated successfully');
        } catch (\Exception $e) {
            return $this->fail('Failed to update settings: ' . $e->getMessage(), 500);
        }
    }

       /* ============================================================
     * ⭐ INSIGHT VISIBILITY (Hide/Unhide KPI values)
     *
     * Stored as a single Setting row:
     *   group = 'insight_visibility'
     *   key   = 'map'
     *   value = { total_approved: bool, total_revenue: bool, rejected: bool }
     *
     * `false` = visible, `true` = hidden.
     * Reuses the existing Setting model — no migration, no new table.
     * ============================================================ */

        private const INSIGHT_VISIBILITY_GROUP = 'insight_visibility';
    private const INSIGHT_VISIBILITY_KEY   = 'map';

    // ⭐ Financial visibility — Hide/Unhide for Order & Events KPI cards.
    //    Same storage pattern as insight_visibility, separate group so the
    //    two features never collide.
    private const FINANCIAL_VISIBILITY_GROUP = 'financial_visibility';
    private const FINANCIAL_VISIBILITY_KEY   = 'map';

    // ⭐ Billing KPI visibility — Hide/Unhide for Billing & Invoicing KPIs.
    private const BILLING_VISIBILITY_GROUP = 'billing_kpi_visibility';
    private const BILLING_VISIBILITY_KEY   = 'map';

    private function defaultInsightVisibility(): array
    {
        return [
            'total_approved' => false,
            'total_revenue'  => false,
            'rejected'       => false,
        ];
    }

     private function defaultFinancialVisibility(): array
    {
        return [
            'total_revenue'       => false,
            'outstanding_balance' => false,
            'payments_collected'  => false,
        ];
    }

    private function defaultBillingVisibility(): array
    {
        return [
            'total_revenue'       => false,
            'total_collected'     => false,
            'outstanding_balance' => false,
            'total_refunds'       => false,
        ];
    }

    /**
     * ⭐ BILLING VISIBILITY — Hide/Unhide for Billing & Invoicing KPIs.
     *    Any authenticated user can READ. Only admin / super-admin can WRITE.
     */
    public function getBillingVisibility(Request $request)
    {
        try {
            $setting = Setting::where('group', self::BILLING_VISIBILITY_GROUP)
                ->where('key', self::BILLING_VISIBILITY_KEY)
                ->first();

            $value = [];
            if ($setting) {
                $value = $this->decodeValue($setting->value, $setting->type);
                if (! is_array($value) && is_string($setting->value)) {
                    $decoded = json_decode($setting->value, true);
                    if (is_array($decoded)) {
                        $value = $decoded;
                    }
                }
            }

            return $this->ok(array_merge($this->defaultBillingVisibility(), [
                'total_revenue'       => (bool) ($value['total_revenue']       ?? false),
                'total_collected'     => (bool) ($value['total_collected']     ?? false),
                'outstanding_balance' => (bool) ($value['outstanding_balance'] ?? false),
                'total_refunds'       => (bool) ($value['total_refunds']       ?? false),
            ]));
        } catch (\Throwable $e) {
            return $this->ok($this->defaultBillingVisibility());
        }
    }

    public function updateBillingVisibility(Request $request)
    {
        try {
            $actor = $request->user();
            if (! $actor) {
                return $this->fail('Unauthenticated.', 401);
            }

            $isAllowed = $actor->roles()
                ->where('is_active', true)
                ->whereIn('slug', ['admin', 'administrator', 'owner', 'super-admin', 'super_admin', 'superadmin'])
                ->exists();

            if (! $isAllowed) {
                return $this->fail('Only administrators can hide or unhide billing values.', 403);
            }

            $input = $request->input('data', $request->all());
            if (! is_array($input)) {
                $input = [];
            }

            $payload = array_merge($this->defaultBillingVisibility(), [
                'total_revenue'       => (bool) ($input['total_revenue']       ?? false),
                'total_collected'     => (bool) ($input['total_collected']     ?? false),
                'outstanding_balance' => (bool) ($input['outstanding_balance'] ?? false),
                'total_refunds'       => (bool) ($input['total_refunds']       ?? false),
            ]);

            Setting::updateOrCreate(
                [
                    'group' => self::BILLING_VISIBILITY_GROUP,
                    'key'   => self::BILLING_VISIBILITY_KEY,
                ],
                [
                    'value' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'type'  => 'string',
                ]
            );

            try {
                Cache::forget('settings_' . self::BILLING_VISIBILITY_GROUP);
            } catch (\Throwable $cacheErr) {
                \Log::warning('billing_visibility cache forget failed: ' . $cacheErr->getMessage());
            }

            try {
                AuditLog::log('system_settings_updated', 'settings', null, null, [
                    'section' => self::BILLING_VISIBILITY_GROUP,
                    'keys'    => array_keys($payload),
                    'values'  => $payload,
                ]);
            } catch (\Throwable $auditErr) {
                \Log::warning('billing_visibility audit log failed: ' . $auditErr->getMessage());
            }

            return $this->ok($payload, 'Billing visibility updated.');
        } catch (\Throwable $e) {
            \Log::error('updateBillingVisibility failed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);
            return $this->fail('Failed to update billing visibility: ' . $e->getMessage(), 500);
        }
    }

    public function getInsightVisibility(Request $request)
    {
        try {
            $setting = Setting::where('group', self::INSIGHT_VISIBILITY_GROUP)
                ->where('key', self::INSIGHT_VISIBILITY_KEY)
                ->first();

            $value = [];
            if ($setting) {
                $value = $this->decodeValue($setting->value, $setting->type);
                // If the stored type collapsed to string but the value is
                // JSON, decode it here.
                if (! is_array($value) && is_string($setting->value)) {
                    $decoded = json_decode($setting->value, true);
                    if (is_array($decoded)) {
                        $value = $decoded;
                    }
                }
            }
            return $this->ok(array_merge($this->defaultInsightVisibility(), [
                'total_approved' => (bool) ($value['total_approved'] ?? false),
                'total_revenue'  => (bool) ($value['total_revenue']  ?? false),
                'rejected'       => (bool) ($value['rejected']       ?? false),
            ]));
        } catch (\Throwable $e) {
            // Never 500 on this call — return the safe default so the UI
            // simply shows everything instead of erroring.
            return $this->ok($this->defaultInsightVisibility());
        }
    }

    public function updateInsightVisibility(Request $request)
    {
        try {
            // Accept either { data: {...} } or a raw body.
            $input = $request->input('data', $request->all());
            if (! is_array($input)) {
                $input = [];
            }

            $payload = array_merge($this->defaultInsightVisibility(), [
                'total_approved' => (bool) ($input['total_approved'] ?? false),
                'total_revenue'  => (bool) ($input['total_revenue']  ?? false),
                'rejected'       => (bool) ($input['rejected']       ?? false),
            ]);

            Setting::updateOrCreate(
                [
                    'group' => self::INSIGHT_VISIBILITY_GROUP,
                    'key'   => self::INSIGHT_VISIBILITY_KEY,
                ],
                [
                    'value' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'type'  => 'string',
                ]
            );

            try {
                Cache::forget('settings_' . self::INSIGHT_VISIBILITY_GROUP);
            } catch (\Throwable $cacheErr) {
                \Log::warning('insight_visibility cache forget failed: ' . $cacheErr->getMessage());
            }

            try {
                $this->logSettingChange(self::INSIGHT_VISIBILITY_GROUP, $payload);
            } catch (\Throwable $auditErr) {
                \Log::warning('insight_visibility audit log failed: ' . $auditErr->getMessage());
            }

            return $this->ok($payload, 'Insight visibility updated.');
        } catch (\Throwable $e) {
            \Log::error('updateInsightVisibility failed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
                'trace'   => $e->getTraceAsString(),
            ]);

            $debug = config('app.debug') ? [
                'exception' => get_class($e),
                'message'   => $e->getMessage(),
                'file'      => $e->getFile(),
                'line'      => $e->getLine(),
            ] : null;

            return $this->fail(
                'Failed to update insight visibility: ' . $e->getMessage(),
                500,
                $debug
            );
        }
    }

    /* ============================================================
     * ⭐ FINANCIAL VISIBILITY (Hide/Unhide for Orders & Events KPIs)
     *
     * Stored as a single Setting row:
     *   group = 'financial_visibility'
     *   key   = 'map'
     *   value = {
     *       total_revenue: bool,
     *       outstanding_balance: bool,
     *       payments_collected: bool
     *   }
     *
     * `false` = visible, `true` = hidden.
     * Any authenticated user can READ (cashiers must see the mask).
     * Only admin / super-admin can WRITE.
     * ============================================================ */

    public function getFinancialVisibility(Request $request)
    {
        try {
            $setting = Setting::where('group', self::FINANCIAL_VISIBILITY_GROUP)
                ->where('key', self::FINANCIAL_VISIBILITY_KEY)
                ->first();

            $value = [];
            if ($setting) {
                $value = $this->decodeValue($setting->value, $setting->type);
                if (! is_array($value) && is_string($setting->value)) {
                    $decoded = json_decode($setting->value, true);
                    if (is_array($decoded)) {
                        $value = $decoded;
                    }
                }
            }

            return $this->ok(array_merge($this->defaultFinancialVisibility(), [
                'total_revenue'       => (bool) ($value['total_revenue']       ?? false),
                'outstanding_balance' => (bool) ($value['outstanding_balance'] ?? false),
                'payments_collected'  => (bool) ($value['payments_collected']  ?? false),
            ]));
        } catch (\Throwable $e) {
            // Never 500 — return the safe default so the UI keeps working.
            return $this->ok($this->defaultFinancialVisibility());
        }
    }

    public function updateFinancialVisibility(Request $request)
    {
        try {
            $actor = $request->user();
            if (! $actor) {
                return $this->fail('Unauthenticated.', 401);
            }

            // ⭐ Only admin / super-admin can hide or unhide.
            $isAllowed = $actor->roles()
                ->where('is_active', true)
                ->whereIn('slug', ['admin', 'administrator', 'owner', 'super-admin', 'super_admin', 'superadmin'])
                ->exists();

            if (! $isAllowed) {
                return $this->fail(
                    'Only administrators can hide or unhide financial values.',
                    403
                );
            }

            $input = $request->input('data', $request->all());
            if (! is_array($input)) {
                $input = [];
            }

            $payload = array_merge($this->defaultFinancialVisibility(), [
                'total_revenue'       => (bool) ($input['total_revenue']       ?? false),
                'outstanding_balance' => (bool) ($input['outstanding_balance'] ?? false),
                'payments_collected'  => (bool) ($input['payments_collected']  ?? false),
            ]);

            Setting::updateOrCreate(
                [
                    'group' => self::FINANCIAL_VISIBILITY_GROUP,
                    'key'   => self::FINANCIAL_VISIBILITY_KEY,
                ],
                [
                    'value' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'type'  => 'string',
                ]
            );

            try {
                Cache::forget('settings_' . self::FINANCIAL_VISIBILITY_GROUP);
            } catch (\Throwable $cacheErr) {
                \Log::warning('financial_visibility cache forget failed: ' . $cacheErr->getMessage());
            }

            try {
                AuditLog::log(
                    'system_settings_updated',
                    'settings',
                    null,
                    null,
                    [
                        'section' => self::FINANCIAL_VISIBILITY_GROUP,
                        'keys'    => array_keys($payload),
                        'values'  => $payload,
                    ]
                );
            } catch (\Throwable $auditErr) {
                \Log::warning('financial_visibility audit log failed: ' . $auditErr->getMessage());
            }

            return $this->ok($payload, 'Financial visibility updated.');
        } catch (\Throwable $e) {
            \Log::error('updateFinancialVisibility failed', [
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ]);

            return $this->fail(
                'Failed to update financial visibility: ' . $e->getMessage(),
                500
            );
        }
    }

    public function updateCompatibility(Request $request)
    {
        $data = $request->validate([
            'section' => ['required', 'string', 'max:80'],
            'data' => ['required', 'array'],
        ]);

        $sectionRequest = Request::create('', 'PUT', ['data' => $data['data']]);
        $sectionRequest->setUserResolver(fn() => $request->user());

        return $this->updateSection($sectionRequest, $data['section']);
    }

    public function reset(Request $request)
    {
        $validated = $request->validate([
            'section' => [
                'nullable',
                'in:pricing,delivery,employee,payroll,inventory,security,booking,' .
                'staff_salary_types,staff_employee_types,staff_working_hours,staff_overtime,' .
                'staff_undertime,staff_late_attendance,staff_holiday_rates,staff_leave_types,' .
                'staff_schedule_rules,staff_payroll_cutoff,staff_government_contributions,' .
                'staff_employee_id,' .
                'system_business,system_security_ext,system_maintenance,' .
                'system_notifications,system_backups',
            ],
        ]);

        $defaults = $this->allDefaults();

        $sections = isset($validated['section'])
            ? [$validated['section'] => $defaults[$validated['section']] ?? []]
            : $defaults;

        DB::transaction(function () use ($sections) {
            foreach ($sections as $section => $values) {
                Setting::where('group', $section)->delete();
                foreach ($values as $key => $value) {
                    $type = $this->detectType($value);
                    Setting::create([
                        'group' => $section,
                        'key' => $key,
                        'value' => $this->encodeValue($value, $type),
                        'type' => $type,
                    ]);
                }
                Cache::forget('settings_' . $section);
            }
        });

        AuditLog::log('system_settings_updated', 'settings', null, null, [
            'action' => 'reset',
            'sections' => array_keys($sections),
        ]);

        return $this->ok(null, 'Settings reset successfully');
    }

    // ============================================================
    // STAFF MANAGEMENT — SNAPSHOT & GROUP UPDATES
    // ============================================================
    public function staffSnapshot(Request $request)
    {
        try {
            $rows = Setting::whereIn('group', self::STAFF_GROUPS)->get()->groupBy('group');

            $out = [];
            foreach (self::STAFF_GROUPS as $group) {
                $items = $rows->get($group, collect());
                $decoded = [];
                foreach ($items as $item) {
                    $decoded[$item->key] = $this->decodeValue($item->value, $item->type);
                }
                $out[$group] = $decoded;
            }

            return $this->ok($out);
        } catch (\Exception $e) {
            return $this->fail('Failed to load staff settings: ' . $e->getMessage(), 500);
        }
    }

    public function updateStaffGroup(Request $request, string $group)
    {
        if (! in_array($group, self::STAFF_GROUPS, true)) {
            return $this->fail('Unknown staff settings group.', 404);
        }

        $data = $request->validate(['data' => ['required', 'array']]);

        DB::transaction(function () use ($group, $data) {
            // Effective-date handling for rate-bearing groups.
            if (in_array($group, ['staff_overtime', 'staff_holiday_rates'], true)) {
                $newFrom = $data['data']['effective_from'] ?? null;
                if ($newFrom) {
                    $previousFrom = Setting::where('group', $group)->where('key', 'effective_from')->first();
                    if ($previousFrom && $previousFrom->value && $previousFrom->value !== $newFrom) {
                        Setting::updateOrCreate(
                            ['group' => $group, 'key' => 'effective_until'],
                            ['value' => Carbon::parse($newFrom)->subDay()->toDateString(), 'type' => 'string']
                        );
                    }
                }
            }

            foreach ($data['data'] as $key => $value) {
                $type = $this->detectType($value);
                Setting::updateOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['value' => $this->encodeValue($value, $type), 'type' => $type]
                );
            }
        });

        Cache::forget('settings_' . $group);
        $this->logSettingChange($group, $data['data']);

        return $this->ok(null, 'Staff settings saved');
    }

    // ============================================================
    // SUPER ADMIN — SNAPSHOT & GROUP UPDATES
    // ============================================================
    public function superAdminSnapshot(Request $request)
    {
        $this->requireSuperAdmin($request);

        try {
            $rows = Setting::whereIn('group', self::SYSTEM_GROUPS)->get()->groupBy('group');

            $out = [];
            foreach (self::SYSTEM_GROUPS as $group) {
                $items = $rows->get($group, collect());
                $decoded = [];
                foreach ($items as $item) {
                    $decoded[$item->key] = $this->decodeValue($item->value, $item->type);
                }
                $out[$group] = $decoded;
            }

            return $this->ok($out);
        } catch (\Exception $e) {
            return $this->fail('Failed to load system settings: ' . $e->getMessage(), 500);
        }
    }

    public function updateSystemGroup(Request $request, string $group)
    {
        $this->requireSuperAdmin($request);

        if (! in_array($group, self::SYSTEM_GROUPS, true)) {
            return $this->fail('Unknown system settings group.', 404);
        }

        $data = $request->validate(['data' => ['required', 'array']]);

        DB::transaction(function () use ($group, $data) {
            foreach ($data['data'] as $key => $value) {
                $type = $this->detectType($value);
                Setting::updateOrCreate(
                    ['group' => $group, 'key' => $key],
                    ['value' => $this->encodeValue($value, $type), 'type' => $type]
                );
            }
        });

        Cache::forget('settings_' . $group);
        $this->logSettingChange($group, $data['data']);

        return $this->ok(null, 'System settings saved');
    }

    public function loginLogs(Request $request)
    {
        $this->requireSuperAdmin($request);

        $query = AuditLog::with(['user.person'])->whereIn('action', [
            'login', 'logout', 'failed_login', 'password_changed',
            'user_banned', 'user_unbanned', 'user_force_logout',
            'user_activated', 'user_deactivated', 'user_created',
        ]);

        if ($request->filled('user_id')) $query->where('user_id', $request->input('user_id'));
        if ($request->filled('action'))  $query->where('action', $request->input('action'));
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('created_at', [
                $request->input('start_date'),
                $request->input('end_date') . ' 23:59:59',
            ]);
        }

        $logs = $query->latest('audit_id')->paginate($request->integer('per_page', 20));

        return $this->ok([
            'data' => $logs->getCollection()->map(fn ($log) => [
                'audit_id'     => $log->audit_id,
                'user_id'      => $log->user_id,
                'user_name'    => $log->user?->person?->full_name ?? $log->user?->username ?? 'System',
                'action'       => $log->action,
                'action_label' => AuditLogCatalog::label($log->action),
                'ip_address'   => $log->ip_address,
                'user_agent'   => $log->user_agent,
                'created_at'   => $log->created_at?->toDateTimeString(),
                'description'  => $this->formatAuditDescription($log),
            ]),
            'total'        => $logs->total(),
            'current_page' => $logs->currentPage(),
            'last_page'    => $logs->lastPage(),
            'per_page'     => $logs->perPage(),
        ]);
    }

    // ============================================================
    // BACKUP & RESTORE
    // ============================================================
    public function listBackups(Request $request)
    {
        $this->requireSuperAdmin($request);

        $dir = storage_path('app/backups');
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $files = collect(File::files($dir))->map(fn ($file) => [
            'filename'   => $file->getFilename(),
            'size'       => $file->getSize(),
            'size_label' => $this->humanFileSize($file->getSize()),
            'created_at' => Carbon::createFromTimestamp($file->getMTime())->toDateTimeString(),
        ])->sortByDesc('created_at')->values();

        return $this->ok(['history' => $files]);
    }

    public function createBackup(Request $request)
    {
        $this->requireSuperAdmin($request);

        $dir = storage_path('app/backups');
        if (! File::exists($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $filename = 'backup-' . now()->format('Ymd-His') . '.sql';
        $fullPath = $dir . DIRECTORY_SEPARATOR . $filename;

        $connection = config('database.default');
        $db = config("database.connections.{$connection}");

        try {
            if (($db['driver'] ?? null) === 'mysql') {
                $cmd = sprintf(
                    'mysqldump --user=%s --password=%s --host=%s --port=%s %s > %s 2>&1',
                    escapeshellarg($db['username'] ?? ''),
                    escapeshellarg($db['password'] ?? ''),
                    escapeshellarg($db['host'] ?? '127.0.0.1'),
                    escapeshellarg((string) ($db['port'] ?? '3306')),
                    escapeshellarg($db['database'] ?? ''),
                    escapeshellarg($fullPath)
                );
                @exec($cmd, $output, $code);

                if ($code !== 0 || ! File::exists($fullPath) || File::size($fullPath) === 0) {
                    return $this->fail(
                        'Backup failed. Ensure `mysqldump` is installed and the DB user has dump privileges.',
                        500
                    );
                }
            } elseif (($db['driver'] ?? null) === 'sqlite') {
                File::copy($db['database'], $fullPath);
            } else {
                return $this->fail('Backup is only supported for MySQL and SQLite connections.', 422);
            }

            AuditLog::log('system_backup_created', 'settings', null, null, [
                'filename' => $filename,
                'size'     => File::size($fullPath),
                'by'       => $request->user()?->user_id,
            ]);

            // Record last-backup timestamp in system backup config.
            Setting::updateOrCreate(
                ['group' => 'system_backup_config', 'key' => 'last_backup_at'],
                ['value' => now()->toIso8601String(), 'type' => 'string']
            );

            return $this->ok([
                'filename'   => $filename,
                'size'       => File::size($fullPath),
                'size_label' => $this->humanFileSize(File::size($fullPath)),
                'created_at' => now()->toDateTimeString(),
            ], 'Backup created successfully');
        } catch (\Throwable $e) {
            return $this->fail('Backup failed: ' . $e->getMessage(), 500);
        }
    }

    public function downloadBackup(Request $request, string $filename)
    {
        $this->requireSuperAdmin($request);

        $safe = basename($filename);
        $path = storage_path('app/backups/' . $safe);

        if (! File::exists($path)) {
            return $this->fail('Backup file not found.', 404);
        }

        return response()->download($path);
    }

    public function restoreBackup(Request $request, string $filename)
    {
        $this->requireSuperAdmin($request);

        $request->validate([
            'confirm' => ['required', 'string', Rule::in(['RESTORE'])],
        ]);

        $safe = basename($filename);
        $path = storage_path('app/backups/' . $safe);

        if (! File::exists($path)) {
            return $this->fail('Backup file not found.', 404);
        }

        $connection = config('database.default');
        $db = config("database.connections.{$connection}");

        try {
            if (($db['driver'] ?? null) === 'mysql') {
                $cmd = sprintf(
                    'mysql --user=%s --password=%s --host=%s --port=%s %s < %s 2>&1',
                    escapeshellarg($db['username'] ?? ''),
                    escapeshellarg($db['password'] ?? ''),
                    escapeshellarg($db['host'] ?? '127.0.0.1'),
                    escapeshellarg((string) ($db['port'] ?? '3306')),
                    escapeshellarg($db['database'] ?? ''),
                    escapeshellarg($path)
                );
                @exec($cmd, $output, $code);

                if ($code !== 0) {
                    return $this->fail('Restore failed. Check DB user privileges.', 500);
                }
            } elseif (($db['driver'] ?? null) === 'sqlite') {
                File::copy($path, $db['database']);
            } else {
                return $this->fail('Restore is only supported for MySQL and SQLite connections.', 422);
            }

            AuditLog::log('system_backup_restored', 'settings', null, null, [
                'filename' => $safe,
                'by'       => $request->user()?->user_id,
            ]);

            return $this->ok(null, 'Database restored successfully.');
        } catch (\Throwable $e) {
            return $this->fail('Restore failed: ' . $e->getMessage(), 500);
        }
    }

    public function deleteBackup(Request $request, string $filename)
    {
        $this->requireSuperAdmin($request);

        $safe = basename($filename);
        $path = storage_path('app/backups/' . $safe);

        if (! File::exists($path)) {
            return $this->fail('Backup file not found.', 404);
        }

        File::delete($path);

        AuditLog::log('system_backup_deleted', 'settings', null, null, [
            'filename' => $safe,
            'by'       => $request->user()?->user_id,
        ]);

        return $this->ok(null, 'Backup deleted.');
    }

    // ============================================================
    // BACKUP CONFIGURATION (NEW)
    // ============================================================
    public function getBackupConfig(Request $request)
    {
        $this->requireSuperAdmin($request);

        $config = [
            'frequency'           => Setting::getValue('system_backup_config', 'frequency', 'manual'),
            'retention'           => (int) Setting::getValue('system_backup_config', 'retention', 7),
            'location'            => Setting::getValue('system_backup_config', 'location', 'storage/app/backups'),
            'auto_backup_enabled' => filter_var(
                Setting::getValue('system_backup_config', 'auto_backup_enabled', false),
                FILTER_VALIDATE_BOOLEAN
            ),
            'last_backup_at'      => Setting::getValue('system_backup_config', 'last_backup_at', null),
            'next_backup_at'      => Setting::getValue('system_backup_config', 'next_backup_at', null),
        ];

        return $this->ok($config);
    }

    public function updateBackupConfig(Request $request)
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'frequency'           => ['required', 'in:manual,daily,weekly,monthly'],
            'retention'           => ['required', 'integer', 'min:1', 'max:365'],
            'auto_backup_enabled' => ['nullable', 'boolean'],
        ]);

        $old = [
            'frequency'           => Setting::getValue('system_backup_config', 'frequency', 'manual'),
            'retention'           => Setting::getValue('system_backup_config', 'retention', 7),
            'auto_backup_enabled' => Setting::getValue('system_backup_config', 'auto_backup_enabled', false),
        ];

        Setting::updateOrCreate(
            ['group' => 'system_backup_config', 'key' => 'frequency'],
            ['value' => $data['frequency'], 'type' => 'string']
        );

        Setting::updateOrCreate(
            ['group' => 'system_backup_config', 'key' => 'retention'],
            ['value' => (string) $data['retention'], 'type' => 'integer']
        );

        Setting::updateOrCreate(
            ['group' => 'system_backup_config', 'key' => 'auto_backup_enabled'],
            [
                'value' => ($data['auto_backup_enabled'] ?? false) ? 'true' : 'false',
                'type'  => 'boolean',
            ]
        );

        try {
            AuditLog::log(
                'backup_config_updated',
                'settings',
                null,
                $old,
                $data,
                'Backup configuration updated'
            );
        } catch (\Throwable $e) {
            // silent
        }

        return $this->ok(null, 'Backup configuration saved');
    }

    // ============================================================
    // ROLE RESTRICTIONS (NEW)
    // ============================================================
    public function getRoleRestrictions(Request $request, string $roleSlug)
    {
        $this->requireSuperAdmin($request);

        $default = [
            'view'     => true,
            'create'   => true,
            'edit'     => true,
            'delete'   => true,
            'approve'  => true,
            'reject'   => true,
            'export'   => true,
            'settings' => false,
        ];

        $stored = Setting::getValue('system_role_restrictions', 'role_' . $roleSlug, []);

        if (is_string($stored)) {
            $stored = json_decode($stored, true) ?: [];
        }

        return $this->ok(array_merge($default, is_array($stored) ? $stored : []));
    }

    public function updateRoleRestrictions(Request $request, string $roleSlug)
    {
        $this->requireSuperAdmin($request);

        $data = $request->validate([
            'view'     => 'nullable|boolean',
            'create'   => 'nullable|boolean',
            'edit'     => 'nullable|boolean',
            'delete'   => 'nullable|boolean',
            'approve'  => 'nullable|boolean',
            'reject'   => 'nullable|boolean',
            'export'   => 'nullable|boolean',
            'settings' => 'nullable|boolean',
        ]);

        Setting::updateOrCreate(
            ['group' => 'system_role_restrictions', 'key' => 'role_' . $roleSlug],
            ['value' => json_encode($data), 'type' => 'json']
        );

        try {
            AuditLog::log(
                'role_restrictions_updated',
                'settings',
                null,
                null,
                ['role' => $roleSlug, 'restrictions' => $data],
                "Role restrictions updated for {$roleSlug}"
            );
        } catch (\Throwable $e) {
            // silent
        }

        return $this->ok($data, 'Role restrictions updated');
    }

    // ============================================================
    // SYSTEM MAINTENANCE
    // ============================================================
    public function systemStatus(Request $request)
    {
        $this->requireSuperAdmin($request);

        $dbOk = true;
        try { DB::connection()->getPdo(); } catch (\Throwable $e) { $dbOk = false; }

        $storagePath = storage_path('app');
        $writable = is_writable($storagePath);

        return $this->ok([
            'app_version'      => config('app.version', '1.0.0'),
            'environment'      => config('app.env'),
            'debug_mode'       => (bool) config('app.debug'),
            'php_version'      => PHP_VERSION,
            'laravel'          => app()->version(),
            'database_ok'      => $dbOk,
            'storage_writable' => $writable,
            'queue_connection' => config('queue.default'),
            'cache_driver'     => config('cache.default'),
            'server_time'      => now()->toDateTimeString(),
            'timezone'         => config('app.timezone'),
        ]);
    }

    public function clearSystemCache(Request $request)
    {
        $this->requireSuperAdmin($request);

        try {
            Artisan::call('cache:clear');
            Artisan::call('config:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');

            Setting::updateOrCreate(
                ['group' => 'system_maintenance', 'key' => 'last_cache_cleared'],
                ['value' => now()->toDateTimeString(), 'type' => 'string']
            );

            AuditLog::log('system_cache_cleared', 'settings', null, null, [
                'by' => $request->user()?->user_id,
            ]);

            return $this->ok(null, 'System cache cleared successfully.');
        } catch (\Throwable $e) {
            return $this->fail('Cache clear failed: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // AUDIT LOGS
    // ============================================================
    public function auditCatalog()
    {
        return $this->ok(AuditLogCatalog::all());
    }

    public function getAuditLogs(Request $request)
    {
        try {
            $query = AuditLog::with(['user.person']);

            if (! $this->isSuperAdmin($request->user())) {
                $query->whereNotIn('table_name', ['users', 'roles', 'permissions', 'settings'])
                    ->whereNotIn('action', ['login', 'logout', 'failed_login', 'role_changed', 'user_created', 'user_activated', 'user_deactivated', 'user_banned', 'user_unbanned', 'user_force_logout', 'password_changed']);
            }

            if ($request->filled('user_id')) $query->where('user_id', $request->input('user_id'));
            if ($request->filled('module')) $query->where('table_name', $request->input('module'));
            if ($request->filled('action')) $query->where('action', $request->input('action'));

            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('created_at', [
                    $request->input('start_date'),
                    $request->input('end_date')
                ]);
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('action', 'like', "%{$search}%")
                        ->orWhere('table_name', 'like', "%{$search}%")
                        ->orWhere('old_values', 'like', "%{$search}%")
                        ->orWhere('new_values', 'like', "%{$search}%");
                });
            }

            $logs = $query->latest('audit_id')->paginate($request->integer('per_page', 20));

            $formattedLogs = $logs->getCollection()->map(function ($log) {
                return [
                    'audit_id' => $log->audit_id,
                    'user_id' => $log->user_id,
                    'user_name' => $log->user?->person?->full_name ?? 'System',
                    'module' => $log->table_name,
                    'module_group' => AuditLogCatalog::moduleForAction($log->action, $log->table_name),
                    'action' => $log->action,
                    'action_label' => AuditLogCatalog::label($log->action),
                    'description' => $this->formatAuditDescription($log),
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'ip_address' => $log->ip_address,
                    'user_agent' => $log->user_agent,
                    'created_at' => $log->created_at?->toDateTimeString(),
                ];
            });

            return $this->ok([
                'data' => $formattedLogs,
                'total' => $logs->total(),
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to load audit logs: ' . $e->getMessage(), 500);
        }
    }

    public function exportAuditLogs(Request $request)
    {
        try {
            $query = AuditLog::with(['user.person']);

            if (! $this->isSuperAdmin($request->user())) {
                $query->whereNotIn('table_name', ['users', 'roles', 'permissions', 'settings'])
                    ->whereNotIn('action', ['login', 'logout', 'failed_login', 'role_changed', 'user_created', 'user_activated', 'user_deactivated', 'user_banned', 'user_unbanned', 'user_force_logout', 'password_changed']);
            }

            if ($request->filled('start_date') && $request->filled('end_date')) {
                $query->whereBetween('created_at', [
                    $request->input('start_date'),
                    $request->input('end_date')
                ]);
            }

            $logs = $query->latest('audit_id')->get();

            $headers = [
                'Content-Type' => 'text/csv',
                'Content-Disposition' => 'attachment; filename="audit_logs_' . now()->format('Y-m-d') . '.csv"',
            ];

            $callback = function () use ($logs) {
                $handle = fopen('php://output', 'w');
                fputcsv($handle, [
                    'ID',
                    'User',
                    'Module',
                    'Action',
                    'Description',
                    'IP Address',
                    'Device',
                    'Created At'
                ]);
                foreach ($logs as $log) {
                    fputcsv($handle, [
                        $log->audit_id,
                        $log->user?->person?->full_name ?? 'System',
                        $log->table_name,
                        $log->action,
                        $this->formatAuditDescription($log),
                        $log->ip_address,
                        $log->user_agent,
                        $log->created_at?->toDateTimeString(),
                    ]);
                }
                fclose($handle);
            };

            return response()->stream($callback, 200, $headers);
        } catch (\Exception $e) {
            return $this->fail('Failed to export audit logs: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // USERS
    // ============================================================
    public function getUsers(Request $request)
    {
        try {
            $query = User::with(['person', 'roles', 'employee.department', 'employee.position']);

            if (! $this->isSuperAdmin($request->user())) {
                $query->whereHas('roles', fn($q) => $q->whereIn('slug', self::ADMIN_MANAGED_ROLES));
            }

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('username', 'like', "%{$search}%")
                        ->orWhereHas('person', function ($p) use ($search) {
                            $p->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            if ($request->filled('role')) {
                $role = $request->input('role');
                $query->whereHas('roles', function ($q) use ($role) {
                    $q->where('slug', $role);
                });
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            $users = $query->latest('user_id')->paginate($request->integer('per_page', 10));

            $formattedUsers = $users->getCollection()->map(function ($user) {
                return [
                    'id' => $user->user_id,
                    'name' => $user->person?->full_name ?? $user->username,
                    'email' => $user->person?->email,
                    'username' => $user->username,
                    'role' => $user->roles->first()?->slug ?? 'staff',
                    'roles' => $user->roles->pluck('slug')->toArray(),
                    'position' => $user->employee?->position?->title,
                    'department' => $user->employee?->department?->name,
                    'is_active' => (bool) $user->is_active,
                    'is_banned' => (bool) ($user->is_banned ?? false),
                    'employee_id' => $user->employee?->employee_id,
                    'employee_code' => $user->employee?->employee_code,
                    'last_login' => $this->formatDateTimeValue($user->last_login_at),
                    'created_at' => $this->formatDateTimeValue($user->created_at),
                    'profile_photo' => $user->person?->profile_photo_url,
                ];
            });

            return $this->ok([
                'data' => $formattedUsers,
                'total' => $users->total(),
                'current_page' => $users->currentPage(),
                'last_page' => $users->lastPage(),
                'per_page' => $users->perPage(),
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to load users: ' . $e->getMessage(), 500);
        }
    }

    public function getEmployeesWithoutAccounts(Request $request)
    {
        try {
            $assignedRoles = [
                'super-admin',
                'admin',
                'cashier',
                'inventory-manager',
                'staff-manager',
            ];

            $query = Employee::with(['person', 'department', 'position', 'user.roles'])
                ->where('status', 'active');

            $query->where(function ($q) use ($assignedRoles) {
                $q->whereNull('user_id')
                    ->orWhereDoesntHave('user.roles', function ($roleQuery) use ($assignedRoles) {
                        $roleQuery->whereIn('slug', $assignedRoles);
                    });
            });

            if ($request->filled('search')) {
                $search = $request->input('search');
                $query->where(function ($q) use ($search) {
                    $q->where('employee_code', 'like', "%{$search}%")
                        ->orWhereHas('person', function ($p) use ($search) {
                            $p->where('first_name', 'like', "%{$search}%")
                                ->orWhere('last_name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            }

            if ($request->filled('department_id') && $request->input('department_id') !== 'all') {
                $query->where('department_id', $request->input('department_id'));
            }

            $employees = $query->orderBy('employee_code')
                ->paginate($request->integer('per_page', 200));

            $formatted = $employees->getCollection()->map(function ($employee) {
                return [
                    'employee_id'   => $employee->employee_id,
                    'employee_code' => $employee->employee_code,
                    'first_name'    => $employee->person?->first_name,
                    'last_name'     => $employee->person?->last_name,
                    'full_name'     => $employee->person?->full_name,
                    'email'         => $employee->person?->email,
                    'phone'         => $employee->person?->phone,
                    'position'      => $employee->position?->title,
                    'position_id'   => $employee->position?->position_id,
                    'department'    => $employee->department?->name,
                    'department_id' => $employee->department?->department_id,
                    'profile_photo' => $employee->person?->profile_photo_url,
                    'hire_date'     => $employee->hire_date,
                    'status'        => $employee->status,
                    'user_id'       => $employee->user_id,
                    'has_account'   => ! is_null($employee->user_id),
                ];
            });

            return $this->ok([
                'data'         => $formatted,
                'total'        => $employees->total(),
                'current_page' => $employees->currentPage(),
                'last_page'    => $employees->lastPage(),
                'per_page'     => $employees->perPage(),
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to load employees: ' . $e->getMessage(), 500);
        }
    }

    public function getEmployeeForAccount(Request $request, $employeeId)
    {
        try {
            $employee = Employee::with(['person', 'department', 'position', 'user.roles'])
                ->where('employee_id', $employeeId)
                ->first();

            if (! $employee) return $this->fail('Employee not found.', 404);

            $assignedRoles = [
                'super-admin',
                'admin',
                'cashier',
                'inventory-manager',
                'staff-manager',
            ];

            $hasSystemRole = $employee->user_id
                && $employee->user
                && $employee->user->roles()->whereIn('slug', $assignedRoles)->exists();

            if ($hasSystemRole) {
                $current = $employee->user->roles()->whereIn('slug', $assignedRoles)->first()?->slug;
                return $this->fail(
                    "This employee already has a system role assigned ({$current}). Use Edit Account to change the role instead.",
                    422
                );
            }

            return $this->ok([
                'employee_id'   => $employee->employee_id,
                'employee_code' => $employee->employee_code,
                'first_name'    => $employee->person?->first_name,
                'last_name'     => $employee->person?->last_name,
                'full_name'     => $employee->person?->full_name,
                'email'         => $employee->person?->email,
                'phone'         => $employee->person?->phone,
                'position'      => $employee->position?->title,
                'position_id'   => $employee->position?->position_id,
                'department'    => $employee->department?->name,
                'department_id' => $employee->department?->department_id,
                'profile_photo' => $employee->person?->profile_photo_url,
                'hire_date'     => $employee->hire_date,
                'status'        => $employee->status,
                'user_id'       => $employee->user_id,
                'has_account'   => ! is_null($employee->user_id),
            ]);
        } catch (\Exception $e) {
            return $this->fail('Failed to load employee: ' . $e->getMessage(), 500);
        }
    }

    public function createUserFromEmployee(Request $request)
    {
        $this->ensureSystemRoles();

        $data = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,employee_id'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_slug' => ['required', 'string', Rule::in($this->assignableRoleSlugs($request->user())), 'exists:roles,slug'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $employee = Employee::with(['person', 'department', 'position', 'user.roles'])
            ->findOrFail($data['employee_id']);

        if (! $employee->person) {
            return $this->fail('The employee is missing its person record.', 422);
        }

        $assignedRoles = [
            'super-admin',
            'admin',
            'cashier',
            'inventory-manager',
            'staff-manager',
        ];

        $role = Role::where('slug', $data['role_slug'])->where('is_active', true)->first();
        if (! $role) {
            return $this->fail('The selected role is inactive or unavailable.', 422);
        }

        if (in_array($role->slug, ['admin', 'super-admin'], true) && ! $this->isSuperAdmin($request->user())) {
            return $this->fail('Only the Super Admin can assign administrator roles.', 403);
        }

        if ($employee->user_id) {
            $existingUser = $employee->user;

            if (! $existingUser) {
                return $this->fail('The employee is linked to a user account that cannot be found.', 422);
            }

            $hasSystemRole = $existingUser->roles()
                ->whereIn('slug', $assignedRoles)
                ->exists();

            if ($hasSystemRole) {
                $currentRole = $existingUser->roles()->whereIn('slug', $assignedRoles)->first()?->slug ?? 'unknown';
                return $this->fail(
                    "This employee already has a system role assigned ({$currentRole}). "
                        . 'Use Edit Account to change the role instead.',
                    422
                );
            }

            $usernameTaken = User::where('username', $data['username'])
                ->where('user_id', '!=', $existingUser->user_id)
                ->exists();

            if ($usernameTaken) {
                return $this->fail('That username is already taken.', 422);
            }

            $user = DB::transaction(function () use ($data, $role, $employee, $existingUser) {
                $existingUser->update([
                    'username' => $data['username'],
                    'password' => Hash::make($data['password']),
                    'is_active' => $data['is_active'] ?? true,
                ]);

                $existingUser->roles()->sync([$role->role_id]);

                AuditLog::log('user_role_assigned', 'users', $existingUser->user_id, null, [
                    'username' => $existingUser->username,
                    'email' => $employee->person->email,
                    'role' => $role->slug,
                    'employee_id' => $employee->employee_id,
                    'employee_code' => $employee->employee_code,
                    'assigned_by' => request()->user()?->user_id,
                ]);

                return $existingUser->load(['person', 'roles', 'employee.department', 'employee.position']);
            });

            return $this->ok($user, 'Account role assigned successfully');
        }

        $usernameTaken = User::where('username', $data['username'])->exists();
        if ($usernameTaken) {
            return $this->fail('That username is already taken.', 422);
        }

        $user = DB::transaction(function () use ($data, $role, $employee) {
            $user = User::create([
                'person_id' => $employee->person_id,
                'username' => $data['username'],
                'password' => Hash::make($data['password']),
                'is_active' => $data['is_active'] ?? true,
                'email_verified_at' => now(),
            ]);

            $user->roles()->sync([$role->role_id]);
            $employee->update(['user_id' => $user->user_id]);

            AuditLog::log('user_created', 'users', $user->user_id, null, [
                'username' => $user->username,
                'email' => $employee->person->email,
                'role' => $role->slug,
                'employee_id' => $employee->employee_id,
                'employee_code' => $employee->employee_code,
                'created_by' => request()->user()?->user_id,
            ]);

            return $user->load(['person', 'roles', 'employee.department', 'employee.position']);
        });

        return $this->ok($user, 'Account created successfully from employee record');
    }

    public function createUser(Request $request)
    {
        $this->ensureSystemRoles();

        $data = $request->validate([
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email', 'max:120', 'unique:persons,email'],
            'username' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:users,username'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role_slug' => ['required', 'string', Rule::in($this->assignableRoleSlugs($request->user())), 'exists:roles,slug'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $role = Role::where('slug', $data['role_slug'])->where('is_active', true)->first();
        if (! $role) return $this->fail('The selected role is inactive or unavailable.', 422);

        if (in_array($role->slug, ['admin', 'super-admin'], true) && ! $this->isSuperAdmin($request->user())) {
            return $this->fail('Only the Super Admin can assign administrator roles.', 403);
        }

        $user = DB::transaction(function () use ($data, $role) {
            $person = Person::create([
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'email' => strtolower($data['email']),
                'phone' => $data['phone'] ?? null,
            ]);

            $user = User::create([
                'person_id' => $person->person_id,
                'username' => $data['username'],
                'password' => Hash::make($data['password']),
                'is_active' => $data['is_active'] ?? true,
                'email_verified_at' => now(),
            ]);

            $user->roles()->sync([$role->role_id]);

            AuditLog::log('user_created', 'users', $user->user_id, null, [
                'username' => $user->username,
                'email' => $person->email,
                'role' => $role->slug,
                'created_by' => request()->user()?->user_id,
            ]);

            return $user->load(['person', 'roles']);
        });

        return $this->ok($user, 'Account created successfully');
    }

    public function getUser(Request $request, User $user)
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return $this->fail('You cannot view or manage this account.', 403);
        }

        $user->load(['person', 'roles.permissions', 'employee.department', 'employee.position']);

        return $this->ok([
            'id' => $user->user_id,
            'user_id' => $user->user_id,
            'name' => $user->person?->full_name ?? $user->username,
            'username' => $user->username,
            'email' => $user->person?->email,
            'phone' => $user->person?->phone,
            'first_name' => $user->person?->first_name,
            'last_name' => $user->person?->last_name,
            'role' => $user->roles->first()?->slug,
            'roles' => $user->roles->pluck('slug')->toArray(),
            'role_name' => $user->roles->first()?->name,
            'is_active' => (bool) $user->is_active,
            'is_banned' => (bool) ($user->is_banned ?? false),
            'employee' => $user->employee ? [
                'employee_id' => $user->employee->employee_id,
                'employee_code' => $user->employee->employee_code,
                'position' => $user->employee->position?->title,
                'department' => $user->employee->department?->name,
                'hire_date' => $user->employee->hire_date,
                'status' => $user->employee->status,
            ] : null,
            'last_login' => $this->formatDateTimeValue($user->last_login_at),
            'created_at' => $this->formatDateTimeValue($user->created_at),
            'profile_photo' => $user->person?->profile_photo_url,
        ]);
    }

    public function updateUser(Request $request, User $user)
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return $this->fail('You cannot modify this account.', 403);
        }

        $data = $request->validate([
            'username' => ['sometimes', 'required', 'string', 'max:50', 'alpha_dash', Rule::unique('users', 'username')->ignore($user->user_id, 'user_id')],
            'is_active' => ['sometimes', 'boolean'],
            'role_slug' => ['sometimes', 'required', 'string', Rule::in($this->assignableRoleSlugs($request->user())), 'exists:roles,slug'],
        ]);

        $oldValues = [
            'username' => $user->username,
            'is_active' => (bool) $user->is_active,
            'roles' => $user->roles()->pluck('slug')->toArray(),
        ];

        if (! empty($data['role_slug'])) {
            $newRole = Role::where('slug', $data['role_slug'])->where('is_active', true)->first();
            if (! $newRole) return $this->fail('Role not found or inactive', 404);

            if (in_array($newRole->slug, ['admin', 'super-admin'], true) && ! $this->isSuperAdmin($request->user())) {
                return $this->fail('Only the Super Admin can assign administrator roles.', 403);
            }

            if (
                $this->isAdministrator($user)
                && ! in_array($newRole->slug, ['admin', 'super-admin'], true)
                && $this->activeAdministratorCount() <= 1
            ) {
                return $this->fail('At least one active administrator account is required.', 422);
            }

            if (
                (int) $request->user()?->user_id === (int) $user->user_id
                && ! in_array($newRole->slug, ['admin', 'super-admin'], true)
            ) {
                return $this->fail('You cannot remove your own administrator access.', 422);
            }
        }

        if (array_key_exists('is_active', $data) && ! $data['is_active']) {
            if ((int) $request->user()?->user_id === (int) $user->user_id) {
                return $this->fail('You cannot deactivate your own account.', 422);
            }
            if ($this->isAdministrator($user) && $this->activeAdministratorCount() <= 1) {
                return $this->fail('At least one active administrator account is required.', 422);
            }
        }

        DB::transaction(function () use ($user, $data, $oldValues) {
            if (array_key_exists('username', $data)) $user->username = $data['username'];
            if (array_key_exists('is_active', $data)) $user->is_active = $data['is_active'];
            $user->save();

            if (! empty($data['role_slug'])) {
                $role = Role::where('slug', $data['role_slug'])->where('is_active', true)->first();
                if ($role) $user->roles()->sync([$role->role_id]);
            }

            if (array_key_exists('is_active', $data) && ! $data['is_active']) {
                $user->tokens()->delete();
            }
        });

        AuditLog::log('user_updated', 'users', $user->user_id, $oldValues, [
            'username' => $user->username,
            'is_active' => (bool) $user->is_active,
            'roles' => $user->roles()->pluck('slug')->toArray(),
        ]);

        return $this->ok($user->fresh(['person', 'roles', 'employee.department', 'employee.position']), 'Account updated successfully');
    }

    public function updateUserRole(Request $request, User $user)
    {
        try {
            if (! $this->canManageUser($request->user(), $user)) {
                return $this->fail('You cannot modify this account.', 403);
            }

            $data = $request->validate([
                'role_slug' => 'required|exists:roles,slug',
            ]);

            $role = Role::where('slug', $data['role_slug'])->where('is_active', true)->first();
            if (!$role) return $this->fail('Role not found or inactive', 404);

            if (! in_array($role->slug, $this->assignableRoleSlugs($request->user()), true)) {
                return $this->fail('You cannot assign this role.', 403);
            }

            if (in_array($role->slug, ['admin', 'super-admin'], true) && ! $this->isSuperAdmin($request->user())) {
                return $this->fail('Only the Super Admin can assign administrator roles.', 403);
            }

            $oldRoles = $user->roles()->pluck('slug')->toArray();
            $isOperationalAccount = collect($oldRoles)
                ->map(fn($slug) => strtolower((string) $slug))
                ->intersect(self::SYSTEM_ACCOUNT_ROLES)
                ->isNotEmpty();

            if ($isOperationalAccount && ! in_array($role->slug, self::SYSTEM_ACCOUNT_ROLES, true)) {
                return $this->fail('Operational Web Admin accounts must use one of the approved system roles.', 422);
            }

            if (
                (int) $request->user()?->user_id === (int) $user->user_id
                && ! in_array($role->slug, ['admin', 'super-admin'], true)
            ) {
                return $this->fail('You cannot remove your own administrator access.', 422);
            }

            if (
                $this->isAdministrator($user)
                && ! in_array($role->slug, ['admin', 'super-admin'], true)
                && $this->activeAdministratorCount() <= 1
            ) {
                return $this->fail('At least one active administrator account is required.', 422);
            }

            $user->roles()->sync([$role->role_id]);

            AuditLog::log('role_changed', 'users', $user->user_id, ['roles' => $oldRoles], [
                'roles' => [$role->slug],
                'user_id' => $user->user_id,
                'username' => $user->username,
            ]);

            try {
                app(\App\Services\NotificationService::class)->notifySystemEvent(
                    'role_updated',
                    "User role updated for {$user->username}.",
                    ['user_id' => $user->user_id, 'reference_id' => $user->user_id, 'role' => $role->slug],
                    ['admin']
                );
            } catch (\Throwable $e) {
                // Silent
            }

            return $this->ok($user->load('roles'), 'User role updated successfully');
        } catch (\Exception $e) {
            return $this->fail('Failed to update user role: ' . $e->getMessage(), 500);
        }
    }

    public function toggleUserActive(Request $request, User $user)
    {
        try {
            if (! $this->canManageUser($request->user(), $user)) {
                return $this->fail('You cannot activate or deactivate this account.', 403);
            }

            if ((int) $request->user()?->user_id === (int) $user->user_id) {
                return $this->fail('You cannot deactivate your own account.', 422);
            }

            if ($user->is_active && $this->isAdministrator($user) && $this->activeAdministratorCount() <= 1) {
                return $this->fail('At least one active administrator account is required.', 422);
            }

            $oldStatus = (bool) $user->is_active;
            $user->update(['is_active' => !$user->is_active]);

            if (! $user->is_active) {
                $user->tokens()->delete();
            }

            AuditLog::log(
                $user->is_active ? 'user_activated' : 'user_deactivated',
                'users',
                $user->user_id,
                ['is_active' => $oldStatus],
                ['is_active' => (bool) $user->is_active, 'username' => $user->username]
            );

            return $this->ok($user, 'User status toggled successfully');
        } catch (\Exception $e) {
            return $this->fail('Failed to toggle user status: ' . $e->getMessage(), 500);
        }
    }

    public function banUser(Request $request, User $user)
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return $this->fail('You cannot ban this account.', 403);
        }

        if ((int) $request->user()?->user_id === (int) $user->user_id) {
            return $this->fail('You cannot ban your own account.', 422);
        }

        if ($this->isSuperAdmin($user) && ! $this->isSuperAdmin($request->user())) {
            return $this->fail('You cannot ban a Super Admin account.', 403);
        }

        $user->update([
            'is_active' => false,
            'is_banned' => true,
            'banned_at' => now(),
            'banned_by' => $request->user()?->user_id,
        ]);

        $user->tokens()->delete();

        AuditLog::log('user_banned', 'users', $user->user_id, null, [
            'username' => $user->username,
            'banned_by' => $request->user()?->user_id,
        ]);

        return $this->ok($user, 'User banned successfully');
    }

    public function unbanUser(Request $request, User $user)
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return $this->fail('You cannot unban this account.', 403);
        }

        $user->update([
            'is_active' => true,
            'is_banned' => false,
            'banned_at' => null,
            'banned_by' => null,
        ]);

        AuditLog::log('user_unbanned', 'users', $user->user_id, null, [
            'username' => $user->username,
            'unbanned_by' => $request->user()?->user_id,
        ]);

        return $this->ok($user, 'User unbanned successfully');
    }

    public function forceLogoutUser(Request $request, User $user)
    {
        if (! $this->canManageUser($request->user(), $user)) {
            return $this->fail('You cannot force logout this account.', 403);
        }

        if ((int) $request->user()?->user_id === (int) $user->user_id) {
            return $this->fail('You cannot force logout your own account from here.', 422);
        }

        $user->tokens()->delete();

        AuditLog::log('user_force_logout', 'users', $user->user_id, null, [
            'username' => $user->username,
            'forced_by' => $request->user()?->user_id,
        ]);

        return $this->ok(null, 'User has been logged out from all devices');
    }

    public function forceChangePassword(Request $request, User $user)
    {
        if (! $this->isSuperAdmin($request->user())) {
            return $this->fail('Only the Super Admin can force change passwords.', 403);
        }

        if (! $this->canManageUser($request->user(), $user)) {
            return $this->fail('You cannot modify this account.', 403);
        }

        $data = $request->validate([
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);

        $user->tokens()->delete();

        AuditLog::log('password_changed', 'users', $user->user_id, null, [
            'username' => $user->username,
            'forced_by' => $request->user()?->user_id,
            'force' => true,
        ]);

        return $this->ok(null, 'Password changed successfully. User has been logged out from all devices.');
    }

    // ============================================================
    // ROLES
    // ============================================================
    public function getRoles(Request $request)
    {
        try {
            $this->ensureSystemRoles();

            $rolesQuery = Role::with('permissions')->orderBy('name');
            if (! $this->isSuperAdmin($request->user())) {
                $rolesQuery->whereIn('slug', self::ADMIN_MANAGED_ROLES);
            }

            $roles = $rolesQuery->get()->map(function ($role) {
                return [
                    'id' => $role->role_id,
                    'name' => $role->name,
                    'slug' => $role->slug,
                    'description' => $role->description,
                    'is_active' => (bool) $role->is_active,
                    'permissions' => $role->permissions->pluck('slug')->toArray(),
                ];
            });

            return $this->ok($roles);
        } catch (\Exception $e) {
            return $this->fail('Failed to load roles: ' . $e->getMessage(), 500);
        }
    }

    public function getRole(Request $request, Role $role)
    {
        if (! $this->isSuperAdmin($request->user()) && ! in_array($role->slug, self::ADMIN_MANAGED_ROLES, true)) {
            return $this->fail('You cannot view this role.', 403);
        }

        return $this->ok($role->load('permissions'));
    }

    public function createRole(Request $request)
    {
        if (! $this->isSuperAdmin($request->user())) {
            return $this->fail('Only the Super Admin can create roles.', 403);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:50', 'unique:roles,name'],
            'slug' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:roles,slug'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,permission_id'],
        ]);

        $role = DB::transaction(function () use ($data) {
            $role = Role::create(collect($data)->except('permission_ids')->all());
            if (array_key_exists('permission_ids', $data)) {
                $role->permissions()->sync($data['permission_ids']);
            }
            return $role->load('permissions');
        });

        return $this->ok($role, 'Role created successfully');
    }

    public function updateRole(Request $request, Role $role)
    {
        if (! $this->isSuperAdmin($request->user())) {
            return $this->fail('Only the Super Admin can modify roles and permissions.', 403);
        }

        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:50', 'unique:roles,name,' . $role->role_id . ',role_id'],
            'slug' => ['sometimes', 'required', 'string', 'max:50', 'alpha_dash', 'unique:roles,slug,' . $role->role_id . ',role_id'],
            'description' => ['nullable', 'string'],
            'is_active' => ['nullable', 'boolean'],
            'permission_ids' => ['nullable', 'array'],
            'permission_ids.*' => ['integer', 'exists:permissions,permission_id'],
        ]);

        DB::transaction(function () use ($role, $data): void {
            $role->update(collect($data)->except('permission_ids')->all());
            if (array_key_exists('permission_ids', $data)) {
                $role->permissions()->sync($data['permission_ids']);
            }
        });

        return $this->ok($role->fresh('permissions'), 'Role updated successfully');
    }

    public function deleteRole(Request $request, Role $role)
    {
        if (! $this->isSuperAdmin($request->user())) {
            return $this->fail('Only the Super Admin can remove roles.', 403);
        }

        if ($role->users()->exists()) {
            return $this->fail('This role is assigned to one or more users and cannot be deleted.', 422);
        }

        $role->permissions()->detach();
        $role->delete();

        return $this->ok(null, 'Role deleted successfully');
    }

    // ============================================================
    // PRIVATE HELPERS
    // ============================================================
    private function requireSuperAdmin(Request $request): void
    {
        if (! $this->isSuperAdmin($request->user())) {
            abort(response()->json([
                'success' => false,
                'message' => 'Only the Super Admin can perform this action.',
            ], 403));
        }
    }

    private function humanFileSize(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = $bytes > 0 ? floor(log($bytes) / log(1024)) : 0;
        $pow = min($pow, count($units) - 1);
        $bytes /= (1 << (10 * $pow));
        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    private function isSuperAdmin(?User $user): bool
    {
        return $user?->roles()->where('is_active', true)->whereIn('slug', ['super-admin', 'super_admin', 'superadmin'])->exists() ?? false;
    }

    private function assignableRoleSlugs(?User $actor): array
    {
        if ($this->isSuperAdmin($actor)) {
            return self::SYSTEM_ACCOUNT_ROLES;
        }
        return self::ADMIN_MANAGED_ROLES;
    }

    private function canManageUser(?User $actor, User $target): bool
    {
        if ($this->isSuperAdmin($actor)) {
            return true;
        }

        if ($this->isAdministrator($target)) {
            return false;
        }

        return $target->roles()->whereIn('slug', self::ADMIN_MANAGED_ROLES)->exists();
    }

    private function formatDateTimeValue($value): ?string
    {
        if ($value === null || $value === '') return null;

        try {
            if ($value instanceof \DateTimeInterface) {
                return Carbon::instance($value)->toDateTimeString();
            }
            return Carbon::parse((string) $value)->toDateTimeString();
        } catch (\Throwable $e) {
            return is_scalar($value) ? (string) $value : null;
        }
    }

    private function decodeValue(?string $value, string $type)
    {
        if ($value === null) return null;

        return match ($type) {
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'integer' => (int) $value,
            'float' => (float) $value,
            'array', 'json' => json_decode($value, true),
            'object' => json_decode($value),
            default => $value,
        };
    }

    private function encodeValue($value, string $type): string
    {
        if (is_array($value) || is_object($value)) return json_encode($value);
        if (is_bool($value)) return $value ? 'true' : 'false';
        return (string) $value;
    }

    private function detectType($value): string
    {
        if (is_array($value)) return 'array';
        if (is_object($value)) return 'object';
        if (is_bool($value)) return 'boolean';
        if (is_int($value)) return 'integer';
        if (is_float($value)) return 'float';
        return 'string';
    }

    private function logSettingChange(string $section, array $data): void
    {
        $action = match ($section) {
            'pricing'             => 'pricing_rules_changed',
            'payroll'             => 'payroll_settings_updated',
            'inventory'           => 'inventory_settings_updated',
            'notifications'       => 'notification_settings_updated',
            'payment'             => 'payment_settings_updated',
            'insight_visibility'  => 'system_settings_updated',
            default               => 'system_settings_updated',
        };

        // ⭐ Never let audit failure break the caller.
        try {
            AuditLog::log(
                $action,
                'settings',
                null,
                null,
                [
                    'section' => $section,
                    'keys'    => array_keys($data),
                ],
                AuditLogCatalog::label($action) ?: 'Settings updated'
            );
        } catch (\Throwable $e) {
            \Log::warning('logSettingChange failed for ' . $section . ': ' . $e->getMessage());
        }
    }

    private function ensureSystemRoles(): void
    {
        $roles = [
            ['name' => 'Super Admin', 'slug' => 'super-admin', 'description' => 'System-wide user, role, permission, settings, audit, security, backup, and override authority.'],
            ['name' => 'Administrator', 'slug' => 'admin', 'description' => 'Operational approval and management authority.'],
            ['name' => 'Cashier', 'slug' => 'cashier', 'description' => 'Quotations, booking requests, customers, invoicing, payment collection, receipts, and sales reports.'],
            ['name' => 'Inventory Manager', 'slug' => 'inventory-manager', 'description' => 'Inventory, suppliers, purchase requests, reservations, waste, equipment, and inventory reports.'],
            ['name' => 'People / Staff Manager', 'slug' => 'staff-manager', 'description' => 'Employees, attendance, scheduling, leave, staffing, performance, and payroll preparation.'],
        ];

        foreach ($roles as $roleData) {
            Role::firstOrCreate(
                ['slug' => $roleData['slug']],
                [
                    'name' => $roleData['name'],
                    'description' => $roleData['description'],
                    'is_active' => true,
                ]
            );
        }
    }

    private function isAdministrator(User $user): bool
    {
        return $user->roles()->whereIn('slug', ['admin', 'super-admin'])->exists();
    }

    private function activeAdministratorCount(): int
    {
        return User::where('is_active', true)
            ->whereHas('roles', fn($query) => $query->whereIn('slug', ['admin', 'super-admin']))
            ->count();
    }

    private function formatAuditDescription($log): string
    {
        $action = AuditLogCatalog::label($log->action);
        $table = ucfirst(str_replace('_', ' ', $log->table_name));
        return "{$action} on {$table}";
    }
    // ============================================================
    // FOOD ALLERGENS MANAGEMENT
    // ============================================================

    /**
     * ⭐ Get the master list of food allergens.
     */
    public function getAllergens(Request $request)
    {
        try {
            $allergens = $this->loadOrSeedAllergens();
            return $this->ok($allergens);
        } catch (\Throwable $e) {
            \Log::error('getAllergens failed: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
            ]);
            return $this->fail('Failed to load allergens: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ⭐ Central loader — always guarantees a non-empty list.
     *    Used by getAllergens, createAllergen, updateAllergen, deleteAllergen
     *    and the customer allergy endpoints.
     */
    private function loadOrSeedAllergens(): array
    {
        $setting = Setting::where('group', self::ALLERGENS_GROUP)
            ->where('key', 'allergens')
            ->first();

        $allergens = [];
        if ($setting && $setting->value) {
            $decoded = json_decode($setting->value, true);
            if (is_array($decoded) && !empty($decoded)) {
                return $decoded;
            }
        }

        // Seed from defaults.
        $defaults = $this->allDefaults();
        $seed = $defaults['food_allergens']['allergens'] ?? [
            ['id' => 'peanuts',     'name' => 'Peanuts',       'description' => 'Peanut and peanut-derived products'],
            ['id' => 'tree_nuts',   'name' => 'Tree Nuts',     'description' => 'Almonds, cashews, walnuts, etc.'],
            ['id' => 'milk',        'name' => 'Milk / Dairy',  'description' => 'Milk, cheese, butter, yogurt'],
            ['id' => 'eggs',        'name' => 'Eggs',          'description' => 'Egg and egg-derived products'],
            ['id' => 'wheat',       'name' => 'Wheat / Gluten','description' => 'Wheat, barley, rye, oats'],
            ['id' => 'soy',         'name' => 'Soy',           'description' => 'Soybeans and soy-derived products'],
            ['id' => 'fish',        'name' => 'Fish',          'description' => 'All fish species'],
            ['id' => 'shellfish',   'name' => 'Shellfish',     'description' => 'Shrimp, crab, lobster, mollusks'],
            ['id' => 'sesame',      'name' => 'Sesame',        'description' => 'Sesame seeds and sesame oil'],
            ['id' => 'sulfites',    'name' => 'Sulfites',      'description' => 'Sulfur dioxide and sulfites'],
        ];

        Setting::updateOrCreate(
            ['group' => self::ALLERGENS_GROUP, 'key' => 'allergens'],
            [
                'value' => json_encode($seed, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'type'  => 'array',
            ]
        );

        return $seed;
    }
    /**
     * ⭐ Create a new food allergen.
     */
    public function createAllergen(Request $request)
    {
        try {
            $validated = $request->validate([
                'name'        => ['required', 'string', 'max:80'],
                'description' => ['nullable', 'string', 'max:255'],
            ]);

               $allergens = $this->loadOrSeedAllergens();

            // Generate a unique slug-style ID from the name.
            $baseId = Str::slug($validated['name'], '_') ?: 'allergen';
            $id = $baseId;
            $counter = 1;
            $existingIds = array_column($allergens, 'id');
            while (in_array($id, $existingIds, true)) {
                $id = $baseId . '_' . $counter++;
            }

            $allergens[] = [
                'id'          => $id,
                'name'        => $validated['name'],
                'description' => $validated['description'] ?? null,
            ];

            Setting::updateOrCreate(
                ['group' => self::ALLERGENS_GROUP, 'key' => 'allergens'],
                [
                    'value' => json_encode(array_values($allergens), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'type'  => 'array',
                ]
            );

            try {
                AuditLog::log('allergen_created', 'settings', null, null, [
                    'allergen_id' => $id,
                    'name'        => $validated['name'],
                ]);
            } catch (\Throwable $e) { /* silent */ }

            return $this->ok($allergens, 'Allergen created successfully.');
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->fail('Failed to create allergen: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ⭐ Update an existing food allergen by its ID.
     */
    public function updateAllergen(Request $request, string $allergenId)
    {
        try {
            $validated = $request->validate([
                'name'        => ['required', 'string', 'max:80'],
                'description' => ['nullable', 'string', 'max:255'],
            ]);

                   $allergens = $this->loadOrSeedAllergens();

            $found = false;
            foreach ($allergens as &$allergen) {
                if (($allergen['id'] ?? null) === $allergenId) {
                    $allergen['name'] = $validated['name'];
                    $allergen['description'] = $validated['description'] ?? null;
                    $found = true;
                    break;
                }
            }
            unset($allergen);

            if (! $found) {
                return $this->fail('Allergen not found.', 404);
            }

            Setting::updateOrCreate(
                ['group' => self::ALLERGENS_GROUP, 'key' => 'allergens'],
                [
                    'value' => json_encode(array_values($allergens), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'type'  => 'array',
                ]
            );

            try {
                AuditLog::log('allergen_updated', 'settings', null, null, [
                    'allergen_id' => $allergenId,
                    'name'        => $validated['name'],
                ]);
            } catch (\Throwable $e) { /* silent */ }

            return $this->ok($allergens, 'Allergen updated successfully.');
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->fail('Failed to update allergen: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ⭐ Delete a food allergen by its ID.
     */
    public function deleteAllergen(string $allergenId)
    {
        try {
                    $allergens = $this->loadOrSeedAllergens();

            $filtered = array_values(array_filter(
                $allergens,
                fn ($allergen) => ($allergen['id'] ?? null) !== $allergenId
            ));

            if (count($filtered) === count($allergens)) {
                return $this->fail('Allergen not found.', 404);
            }

            Setting::updateOrCreate(
                ['group' => self::ALLERGENS_GROUP, 'key' => 'allergens'],
                [
                    'value' => json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'type'  => 'array',
                ]
            );

            try {
                AuditLog::log('allergen_deleted', 'settings', null, null, [
                    'allergen_id' => $allergenId,
                ]);
            } catch (\Throwable $e) { /* silent */ }

            return $this->ok($filtered, 'Allergen deleted successfully.');
        } catch (\Throwable $e) {
            return $this->fail('Failed to delete allergen: ' . $e->getMessage(), 500);
        }
    }
    // ============================================================
    // CUSTOMER FOOD ALLERGIES
    // ============================================================

    /**
     * ⭐ Return the current customer's saved allergies.
     * Returns the allergen SLUGS (matching food_allergens list IDs).
     */
    public function getMyAllergies(Request $request)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return $this->fail('Unauthenticated.', 401);
            }

            $person = $user->person;
            if (! $person) {
                return $this->ok([]);
            }

            $allergies = is_array($person->allergies) ? $person->allergies : [];
            return $this->ok(array_values($allergies));
        } catch (\Throwable $e) {
            return $this->fail('Failed to load allergies: ' . $e->getMessage(), 500);
        }
    }

    /**
     * ⭐ Save the current customer's allergies.
     * Accepts an array of allergen slugs from the master `food_allergens` list.
     */
    public function updateMyAllergies(Request $request)
    {
        try {
            $user = $request->user();
            if (! $user) {
                return $this->fail('Unauthenticated.', 401);
            }

            $validated = $request->validate([
                'allergies'   => ['present', 'array'],
                'allergies.*' => ['string', 'max:80'],
            ]);

            // Normalize + validate against master list.
            $submitted = collect($validated['allergies'])
                ->map(fn ($v) => strtolower(trim((string) $v)))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $masterList = $this->loadOrSeedAllergens();
            $masterIds = array_map(
                fn ($a) => strtolower((string) ($a['id'] ?? '')),
                $masterList
            );

            $clean = array_values(array_intersect($submitted, $masterIds));

            $person = $user->person;
            if (! $person) {
                return $this->fail('No person record found for this user.', 422);
            }

            $person->update(['allergies' => $clean]);

            try {
                AuditLog::log('customer_allergies_updated', 'persons', $person->person_id, null, [
                    'user_id'   => $user->user_id,
                    'allergies' => $clean,
                ]);
            } catch (\Throwable $e) { /* silent */ }

            return $this->ok($clean, 'Allergies saved successfully.');
        } catch (ValidationException $e) {
            return $this->fail('Validation failed', 422, $e->errors());
        } catch (\Throwable $e) {
            return $this->fail('Failed to save allergies: ' . $e->getMessage(), 500);
        }
    }

    // ============================================================
    // DEFAULTS
    // ============================================================
    private function allDefaults(): array
    {
        return [
            'pricing' => [
                'base_price_per_head' => 350,
                'package_pricing' => [],
                'seasonal_pricing' => [],
                'discount_rules' => [],
                'tax_settings' => ['tax_percentage' => 12, 'service_charge' => 10, 'is_tax_inclusive' => true],
                'promo_codes' => [],
            ],
            'delivery' => [
                'delivery_zones' => [],
                'free_delivery_threshold' => 10000,
                'pickup_allowed' => true,
                'delivery_time_slots' => ['9:00 AM - 11:00 AM', '11:00 AM - 1:00 PM', '1:00 PM - 3:00 PM', '3:00 PM - 5:00 PM'],
                'distance_based_fee' => false,
                'fee_per_km' => 50,
                'max_delivery_radius' => 50,
                'vehicle_assignment' => [],
            ],
            'employee' => [
                'grace_period_minutes' => 10,
                'late_deduction_per_minute' => 5,
                'sick_leave_days_per_year' => 15,
                'vacation_leave_days_per_year' => 15,
                'attendance_tracking' => true,
                'performance_rating' => true,
                'skills_tagging' => true,
            ],
            'payroll' => [
                'daily_wage' => 600,
                'hourly_rate' => 75,
                'overtime_rate' => 1.5,
                'sss_employee_rate' => 0.045,
                'sss_cutoff_cap' => 200,
                'philhealth_employee_rate' => 0.025,
                'philhealth_cutoff_cap' => 150,
                'pagibig_employee_rate' => 0.02,
                'pagibig_cutoff_cap' => 100,
                'withholding_tax_threshold' => 10417,
                'withholding_tax_rate' => 0.10,
                'auto_generate_payroll' => true,
                'attendance_based' => true,
                'overtime_calculation' => true,
            ],
            'inventory' => [
                'low_stock_threshold' => 10,
                'reorder_level' => 20,
                'ingredient_buffer_percentage' => 5,
                'yield_percentage' => 95,
                'auto_deduct_inventory' => true,
                'stock_report_enabled' => true,
                'expiration_tracking' => false,
            ],
            'security' => [
                'password_min_length' => 8,
                'session_timeout_minutes' => 30,
                'login_attempts_limit' => 5,
                'two_factor_auth' => false,
                'password_encryption' => true,
                'device_login_tracking' => true,
                'activity_alerts' => true,
                'account_lock_duration' => 30,
                'suspicious_activity_threshold' => 5,
            ],
                'booking' => [
                'minimum_pax' => 10,
                'allow_same_day_booking' => false,
                'allow_holiday_booking' => false,
                'allow_weekend_booking' => true,
                'cancellation_cutoff_days' => 3,
                'deposit_payment_days' => 7,
                'deposit_amount' => 5000,
                'deposit_percentage' => 30,
                'require_deposit' => true,
            ],
            'food_allergens' => [
                'allergens' => [
                    ['id' => 'peanuts', 'name' => 'Peanuts', 'description' => 'Peanut and peanut-derived products'],
                    ['id' => 'tree_nuts', 'name' => 'Tree Nuts', 'description' => 'Almonds, cashews, walnuts, etc.'],
                    ['id' => 'milk', 'name' => 'Milk / Dairy', 'description' => 'Milk, cheese, butter, yogurt'],
                    ['id' => 'eggs', 'name' => 'Eggs', 'description' => 'Egg and egg-derived products'],
                    ['id' => 'wheat', 'name' => 'Wheat / Gluten', 'description' => 'Wheat, barley, rye, oats'],
                    ['id' => 'soy', 'name' => 'Soy', 'description' => 'Soybeans and soy-derived products'],
                    ['id' => 'fish', 'name' => 'Fish', 'description' => 'All fish species'],
                    ['id' => 'shellfish', 'name' => 'Shellfish', 'description' => 'Shrimp, crab, lobster, mollusks'],
                    ['id' => 'sesame', 'name' => 'Sesame', 'description' => 'Sesame seeds and sesame oil'],
                    ['id' => 'sulfites', 'name' => 'Sulfites', 'description' => 'Sulfur dioxide and sulfites'],
                ],
            ],

            'staff_salary_types' => [
                'fixed' => [
                    'code' => 'fixed',
                    'name' => 'Fixed Salary',
                    'description' => 'Monthly salary remains fixed. OT / undertime / late are recorded but do not auto-adjust salary.',
                    'rules' => [
                        'auto_adjust_overtime' => false,
                        'auto_adjust_undertime' => false,
                        'auto_adjust_late' => false,
                        'require_time_in_out' => true,
                    ],
                    'is_active' => true,
                ],
                'hourly' => [
                    'code' => 'hourly',
                    'name' => 'Hourly / Computed',
                    'description' => 'Pay is computed from actual hours worked plus approved OT, minus undertime/late where applicable.',
                    'rules' => [
                        'auto_adjust_overtime' => true,
                        'auto_adjust_undertime' => true,
                        'auto_adjust_late' => true,
                        'require_time_in_out' => true,
                    ],
                    'is_active' => true,
                ],
            ],
            'staff_employee_types' => [
                'regular' => ['code' => 'regular', 'name' => 'Regular', 'description' => 'Full-time regular employee.', 'is_active' => true],
                'probationary' => ['code' => 'probationary', 'name' => 'Probationary', 'description' => 'Under evaluation period.', 'is_active' => true],
                'on_call' => ['code' => 'on_call', 'name' => 'On-Call', 'description' => 'Called as needed.', 'is_active' => true],
                'contract' => ['code' => 'contract', 'name' => 'Contract', 'description' => 'Fixed-term contract.', 'is_active' => true],
            ],
            'staff_working_hours' => [
                'standard_hours_per_day' => 8,
                'standard_working_days' => 22,
                'break_minutes' => 60,
                'grace_period_minutes' => 10,
                'min_working_hours' => 4,
                'max_working_hours' => 12,
            ],
            'staff_overtime' => [
                'regular_day_rate' => 1.25,
                'rest_day_rate' => 1.30,
                'special_holiday_rate' => 1.30,
                'regular_holiday_rate' => 2.00,
                'min_minutes' => 30,
                'max_hours' => 4,
                'requires_approval' => true,
                'effective_from' => now()->toDateString(),
                'effective_until' => null,
                'is_active' => true,
            ],
            'staff_undertime' => [
                'enabled' => true,
                'deduction_rate' => 1.00,
                'min_minutes' => 15,
                'requires_approval' => true,
                'disabled_for_fixed_salary' => true,
            ],
            'staff_late_attendance' => [
                'grace_period_minutes' => 10,
                'late_threshold_minutes' => 1,
                'deduction_enabled' => true,
                'min_late_minutes' => 1,
                'requires_approval' => true,
                'disabled_for_fixed_salary' => true,
            ],
            'staff_holiday_rates' => [
                'special_holiday_rate' => 1.30,
                'regular_holiday_rate' => 2.00,
                'rest_day_rate' => 1.30,
                'effective_from' => now()->toDateString(),
                'is_active' => true,
            ],
            'staff_leave_types' => [
                'vacation' => ['code' => 'vacation', 'name' => 'Vacation Leave', 'max_days_per_year' => 15, 'requires_approval' => true, 'is_paid' => true, 'is_active' => true],
                'sick' => ['code' => 'sick', 'name' => 'Sick Leave', 'max_days_per_year' => 15, 'requires_approval' => true, 'is_paid' => true, 'is_active' => true],
                'emergency' => ['code' => 'emergency', 'name' => 'Emergency Leave', 'max_days_per_year' => 5, 'requires_approval' => true, 'is_paid' => true, 'is_active' => true],
                'personal' => ['code' => 'personal', 'name' => 'Personal Leave', 'max_days_per_year' => 5, 'requires_approval' => true, 'is_paid' => false, 'is_active' => true],
                'other' => ['code' => 'other', 'name' => 'Other Leave', 'max_days_per_year' => 0, 'requires_approval' => true, 'is_paid' => false, 'is_active' => true],
            ],
            'staff_schedule_rules' => [
                'max_working_hours_per_day' => 8,
                'max_working_days_per_week' => 6,
                'minimum_rest_hours' => 8,
                'check_schedule_conflict' => true,
                'check_leave_conflict' => true,
                'check_existing_schedule' => true,
                'check_overtime_conflict' => true,
            ],
            'staff_payroll_cutoff' => [
                'first_cutoff_start' => 1,
                'first_cutoff_end' => 15,
                'second_cutoff_start' => 16,
                'second_cutoff_end' => 'end_of_month',
                'auto_lock_after_generate' => true,
            ],
            'staff_government_contributions' => [
                'sss' => [
                    'employee_rate' => 0.045,
                    'employer_rate' => 0.095,
                    'cutoff_cap' => 200,
                    'eligible_types' => ['regular', 'probationary', 'contract'],
                ],
                'philhealth' => [
                    'employee_rate' => 0.025,
                    'employer_rate' => 0.025,
                    'cutoff_cap' => 150,
                    'eligible_types' => ['regular', 'probationary', 'contract'],
                ],
                'pagibig' => [
                    'employee_rate' => 0.02,
                    'employer_rate' => 0.02,
                    'cutoff_cap' => 100,
                    'eligible_types' => ['regular', 'probationary', 'contract'],
                ],
            ],
            'staff_employee_id' => [
                'prefix' => 'EMP-',
                'starting_number' => 1,
                'digits' => 4,
                'auto_generate' => true,
            ],

            'system_business' => [
                'business_name' => "Dear Bab's Catering",
                'logo_url' => null,
                'email' => null,
                'phone' => null,
                'address' => null,
                'system_name' => 'Dear Babs CMS',
                'currency' => 'PHP',
                'currency_symbol' => '₱',
                'date_format' => 'Y-m-d',
                'time_format' => 'H:i',
                'timezone' => 'Asia/Manila',
            ],
            'system_security_ext' => [
                'password_min_length' => 8,
                'password_require_upper' => true,
                'password_require_number' => true,
                'password_require_symbol' => false,
                'max_login_attempts' => 5,
                'account_lockout_minutes' => 30,
                'session_timeout_minutes' => 30,
                'auto_logout' => true,
                'two_factor_auth' => false,
                'login_notifications' => true,
                'suspicious_login_alerts' => true,
            ],
            'system_maintenance' => [
                'maintenance_mode' => false,
                'maintenance_message' => 'We are performing scheduled maintenance. Please try again shortly.',
                'allowed_ips' => [],
                'last_cache_cleared' => null,
            ],
            'system_notifications' => [
                'channels' => ['in_system' => true, 'email' => false],
                'events' => [
                    'new_booking' => true,
                    'booking_approval' => true,
                    'inventory_shortage' => true,
                    'purchase_request' => true,
                    'attendance_approval' => true,
                    'overtime_approval' => true,
                    'payment_verification' => true,
                    'overdue_account' => true,
                    'event_completion' => true,
                    'security_alert' => true,
                ],
            ],
            'system_backups' => [
                'history' => [],
            ],
        ];
    }
}