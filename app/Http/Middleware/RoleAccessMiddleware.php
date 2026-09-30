<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleAccessMiddleware
{
    private const ROLE_ALIASES = [
        'super_admin' => 'super-admin',
        'superadmin' => 'super-admin',
        'administrator' => 'admin',
        'owner' => 'admin',
        'finance' => 'cashier',
        'finance-staff' => 'cashier',
        'finance_staff' => 'cashier',
        'people-manager' => 'staff-manager',
        'people_manager' => 'staff-manager',
        'staff_manager' => 'staff-manager',
        'inventory_manager' => 'inventory-manager',
        'head_chef' => 'head-chef',
    ];

    private const CONTROLLED_ROLES = [
        'super-admin',
        'admin',
        'cashier',
        'inventory-manager',
        'staff-manager',
        'head-chef',
    ];

    private const NON_ADMIN_PORTAL_ROLES = [
        'customer',
        'employee',
    ];

    /**
     * Human-readable message for each restricted action.
     */
    private const ACTION_MESSAGES = [
        'approve'  => 'You cannot approve please contact super admin!',
        'reject'   => 'You cannot reject please contact super admin!',
        'create'   => 'You cannot create please contact super admin!',
        'edit'     => 'You cannot edit please contact super admin!',
        'delete'   => 'You cannot delete please contact super admin!',
        'view'     => 'You cannot view please contact super admin!',
        'export'   => 'You cannot export please contact super admin!',
        'settings' => 'You cannot access settings please contact super admin!',
    ];

    private const APPROVE_ACTION_SEGMENTS = [
        'confirm',
        'approve',
        'approve-refund',
        'approve-reschedule',
        'approve-overtime',
        'approve-undertime',
        'approve-unscheduled',
        'approve-all',
        'approve-selected',
        'verify',
        'mark-paid',
        'send',
        'release',
    ];

    private const REJECT_ACTION_SEGMENTS = [
        'reject',
        'decline',
        'reject-refund',
        'reject-reschedule',
        'reject-overtime',
        'reject-undertime',
        'cancel',
        'cancel-with-reason',
        'unverify',
        'undecline',
        'unapprove',
    ];

    private const EXPORT_ACTION_SEGMENTS = [
        'export',
        'download',
        'download-receipt',
        'payslip',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated. Please log in.',
            ], 401);
        }

        $roles = $user->roles()
            ->where('is_active', true)
            ->pluck('slug')
            ->map(fn($role) => $this->normalizeRole((string) $role))
            ->unique()
            ->values()
            ->all();

        $controlledRoles = array_values(array_intersect($roles, self::CONTROLLED_ROLES));

        if ($controlledRoles === []) {
            $unexpectedRoles = array_values(array_diff($roles, self::NON_ADMIN_PORTAL_ROLES));
            if ($unexpectedRoles === []) {
                return $next($request);
            }

            return response()->json([
                'success' => false,
                'message' => 'Forbidden. This role has no authorized operational module.',
            ], 403);
        }

        $path = $this->relativeApiPath($request);
        $method = strtoupper($request->method());

        if (in_array('super-admin', $controlledRoles, true)) {
            return $next($request);
        }

        if ($this->isSharedAccountEndpoint($path, $method)) {
            return $next($request);
        }

        foreach ($controlledRoles as $role) {
            $restrictions = $this->getRestrictions($role);

            if (empty($restrictions)) {
                continue;
            }

            $deniedFlag = $this->detectDeniedFlag($restrictions, $path, $method);

            if ($deniedFlag !== null) {
                return response()->json([
                    'success' => false,
                    'message' => $this->messageForAction($deniedFlag),
                    'error_code' => 'ROLE_ACTION_RESTRICTED',
                    'restricted_action' => $deniedFlag,
                ], 403);
            }
        }

        foreach ($controlledRoles as $role) {
            if ($this->roleCanAccess($role, $path, $method)) {
                return $next($request);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Forbidden. Your assigned role cannot perform this action.',
            'required_module' => $this->moduleForPath($path),
        ], 403);
    }

    private function messageForAction(string $flag): string
    {
        return self::ACTION_MESSAGES[$flag]
            ?? 'You cannot perform this action please contact super admin!';
    }

    private function getRestrictions(string $role): array
    {
        try {
            $stored = \App\Models\Setting::getValue('system_role_restrictions', 'role_' . $role, []);

            if (is_string($stored)) {
                $stored = json_decode($stored, true) ?: [];
            }

            return is_array($stored) ? $stored : [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private function detectDeniedFlag(array $restrictions, string $path, string $method): ?string
    {
        if (! ($restrictions['settings'] ?? true) && $this->startsWithAny($path, ['settings'])) {
            return 'settings';
        }

        $segments = explode('/', trim($path, '/'));

        foreach ($segments as $segment) {
            if (in_array($segment, self::APPROVE_ACTION_SEGMENTS, true)) {
                if (! ($restrictions['approve'] ?? true)) {
                    return 'approve';
                }
                return null;
            }

            if (in_array($segment, self::REJECT_ACTION_SEGMENTS, true)) {
                if (! ($restrictions['reject'] ?? true)) {
                    return 'reject';
                }
                return null;
            }

            if (in_array($segment, self::EXPORT_ACTION_SEGMENTS, true)) {
                if (! ($restrictions['export'] ?? true)) {
                    return 'export';
                }
                return null;
            }
        }

        if ($method === 'GET') {
            if (! ($restrictions['view'] ?? true)) {
                return 'view';
            }
            return null;
        }

        if ($method === 'POST') {
            if (! ($restrictions['create'] ?? true)) {
                return 'create';
            }
            return null;
        }

        if ($method === 'PUT' || $method === 'PATCH') {
            if (! ($restrictions['edit'] ?? true)) {
                return 'edit';
            }
            return null;
        }

        if ($method === 'DELETE') {
            if (! ($restrictions['delete'] ?? true)) {
                return 'delete';
            }
            return null;
        }

        return null;
    }

    private function roleCanAccess(string $role, string $path, string $method): bool
    {
        return match ($role) {
            'admin' => $this->adminCanAccess($path, $method),
            'cashier' => $this->cashierCanAccess($path, $method),
            'inventory-manager' => $this->inventoryManagerCanAccess($path, $method),
            'staff-manager' => $this->staffManagerCanAccess($path, $method),
            'head-chef' => $this->headChefCanAccess($path, $method),
            default => false,
        };
    }

    private function adminCanAccess(string $path, string $method): bool
    {
        if ($this->startsWithAny($path, ['roles']) && $method !== 'GET') {
            return false;
        }

        if ($this->startsWithAny($path, ['settings'])) {
            return $this->adminCanAccessBookingSettings($path, $method);
        }

        if ($this->startsWithAny($path, ['refunds'])) {
            return true;
        }

        return true;
    }
    private function adminCanAccessBookingSettings(string $path, string $method): bool
    {
        if ($path === 'settings' && $method === 'GET') {
            return true;
        }

        if ($path === 'settings/booking') {
            return in_array($method, ['GET', 'PUT', 'POST', 'PATCH'], true);
        }

        // ⭐ Admin can READ all settings sections so any page that loads
        //    a section snapshot (business, payroll, inventory, etc.) does
        //    not 403. Writing is still restricted to the sections below.
        if ($method === 'GET' && $this->startsWithAny($path, ['settings'])) {
            return true;
        }

        // ⭐ Admin can WRITE the booking section and the insight visibility
        //    section. Everything else requires super-admin.
              if ($path === 'settings/insight-visibility') {
            return in_array($method, ['GET', 'PUT', 'POST', 'PATCH'], true);
        }

        if ($path === 'settings/financial-visibility') {
            return in_array($method, ['GET', 'PUT', 'POST', 'PATCH'], true);
        }
        return false;
    }

    private function cashierCanAccess(string $path, string $method): bool
    {
        if ($this->cashierDashboardCanAccess($path, $method)) {
            return true;
        }

        if ($this->startsWithAny($path, ['refunds'])) {
            return false;
        }
        if ($method === 'GET' && $path === 'settings/business') {
            return true;
        }

        // ⭐ Cashiers may READ the insight visibility state so their
        // KPI cards reflect whatever the admin has hidden. They cannot
        // write — the PUT is not whitelisted for cashiers, and it also
        // carries the `role:admin,super-admin` middleware in routes/api.php.
        //
        // Match both the exact path and any trailing slash variant so a
        // `settings/insight-visibility/` typo in a client never falls through.
              if ($method === 'GET' && rtrim($path, '/') === 'settings/insight-visibility') {
            return true;
        }

        if ($method === 'GET' && rtrim($path, '/') === 'settings/financial-visibility') {
            return true;
        }

        if ($method === 'GET' && $this->startsWithAny($path, [
            'bookings',
            'bookings-statistics',
            'booking-calendar',
            'calendar-events',
            'quotations',
            'customers',
            'customer-messages',
            'invoices',
            'debts',
            'payments',
            'deposits',
            'financial-reports/sales',
            'reports/sales',
            'menu-items',
            'packages',
            'promotions',
            'meal-categories',
            'event-types',
            'delivery-zones',
        ])) {
            return true;
        }

        if ($this->matches($path, '#^quotations(?:/[^/]+)?$#') && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return true;
        }
        if ($method === 'POST' && $this->matches($path, '#^quotations/[^/]+/send$#')) {
            return true;
        }

        if ($this->matches($path, '#^bookings(?:/[^/]+)?$#') && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return true;
        }
        if ($method === 'POST' && $this->matches($path, '#^bookings/[^/]+/(record-payment|request-reschedule)$#')) {
            return true;
        }

        if ($method === 'POST' && $this->matches($path, '#^bookings/[^/]+/request-refund$#')) {
            return true;
        }

        if ($this->matches($path, '#^customers(?:/[^/]+)?$#') && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return true;
        }
        if ($method === 'POST' && $this->matches($path, '#^customers/[^/]+/send-email$#')) {
            return true;
        }
        if ($method === 'POST' && $path === 'customer-messages') {
            return true;
        }

        if ($this->matches($path, '#^invoices(?:/[^/]+)?$#') && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            return true;
        }
        if ($method === 'POST' && $this->matches($path, '#^invoices/[^/]+/reminder$#')) {
            return true;
        }
        if ($method === 'POST' && in_array($path, ['payments', 'payments/mobile', 'deposits/send-reminders'], true)) {
            return true;
        }

        return false;
    }

    private function inventoryManagerCanAccess(string $path, string $method): bool
    {
        if ($this->startsWithAny($path, ['refunds'])) {
            return false;
        }

        if ($this->inventoryDashboardCanAccess($path, $method)) {
            return true;
        }

        if ($method === 'GET' && $this->startsWithAny($path, [
            'inventory',
            'inventory-history',
            'ingredients',
            'products',
            'equipment',
            'suppliers',
            'shopping-list',
            'reports/inventory',
            'bookings',
            'events',
            'delivery-zones',
        ])) {
            return true;
        }

        if ($this->startsWithAny($path, [
            'inventory',
            'inventory-history',
            'ingredients',
            'products',
            'equipment',
            'suppliers',
            'shopping-list',
        ]) && in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return true;
        }

        if ($method === 'POST' && $this->matches(
            $path,
            '#^bookings/[^/]+/ingredients-mark-(?:purchased|all-purchased)$#'
        )) {
            return true;
        }

        if (
            $this->matches($path, '#^events/[^/]+/equipment(?:/.*)?$#')
            && in_array($method, ['POST', 'PUT', 'PATCH'], true)
        ) {
            return true;
        }
        if ($method === 'POST' && $this->matches($path, '#^events/[^/]+/return-equipment$#')) {
            return true;
        }

        return false;
    }

    private function staffManagerCanAccess(string $path, string $method): bool
    {
        if ($this->startsWithAny($path, ['refunds'])) {
            return false;
        }

        if ($this->staffDashboardCanAccess($path, $method)) {
            return true;
        }

        if ($this->startsWithAny($path, [
            'employees',
            'departments',
            'positions',
            'salary-grades',
            'schedules',
            'attendance',
            'daily-attendance',
            'employee-requests',
            'leave-requests',
            'shift-types',
        ])) {
            return true;
        }

        if ($method === 'GET' && $this->startsWithAny($path, [
            'payroll',
            'payslips',
            'reports/payroll',
            'events',
            'event-calendar',
        ])) {
            return true;
        }

        if ($method === 'POST' && in_array($path, ['payroll/preview', 'payroll/process', 'payroll/bulk-deductions'], true)) {
            return true;
        }
        if ($method === 'PUT' && $this->matches($path, '#^payroll/[^/]+$#')) {
            return true;
        }
        if ($method === 'POST' && in_array($path, [
            'attendance/generate-summary',
            'attendance/save-summary-to-payroll',
            'attendance/save-all-summaries-to-payroll',
        ], true)) {
            return true;
        }

        if (
            $this->matches($path, '#^events/[^/]+/staff(?:/[^/]+(?:/status)?)?$#')
            && in_array($method, ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'], true)
        ) {
            return true;
        }

        return false;
    }

    private function headChefCanAccess(string $path, string $method): bool
    {
        if ($this->startsWithAny($path, ['refunds'])) {
            return false;
        }

        if ($this->isDashboardRead($path, $method)) {
            return true;
        }

        if ($this->startsWithAny($path, [
            'menu-items',
            'menu-statistics',
            'meal-categories',
            'recipes',
            'ingredients',
            'products',
        ])) {
            return true;
        }

        return $method === 'GET' && $this->startsWithAny($path, ['packages', 'promotions', 'event-types']);
    }

    private function cashierDashboardCanAccess(string $path, string $method): bool
    {
        return $method === 'GET' && in_array($path, [
            'dashboard',
            'dashboard/stats',
            'dashboard/charts',
            'dashboard/monthly-summary',
            'dashboard/recent-bookings',
            'dashboard/upcoming-events',
            'dashboard/revenue-chart',
            'dashboard/event-distribution',
        ], true);
    }

    private function inventoryDashboardCanAccess(string $path, string $method): bool
    {
        return $method === 'GET' && in_array($path, [
            'dashboard',
            'dashboard/stats',
            'dashboard/charts',
            'dashboard/monthly-summary',
            'dashboard/upcoming-events',
            'dashboard/low-stock',
        ], true);
    }

    private function staffDashboardCanAccess(string $path, string $method): bool
    {
        return $method === 'GET' && in_array($path, [
            'dashboard',
            'dashboard/stats',
            'dashboard/charts',
            'dashboard/monthly-summary',
            'dashboard/upcoming-events',
            'dashboard/today-attendance',
        ], true);
    }

    private function isDashboardRead(string $path, string $method): bool
    {
        return $method === 'GET' && $this->startsWithAny($path, ['dashboard']);
    }

    private function isSharedAccountEndpoint(string $path, string $method): bool
    {
        if ($this->startsWithAny($path, ['auth'])) {
            return true;
        }

        if ($this->startsWithAny($path, ['notifications'])) {
            return ! ($path === 'notifications' && $method === 'POST');
        }

        return false;
    }

    private function moduleForPath(string $path): string
    {
        return match (true) {
            $this->startsWithAny($path, ['users', 'roles']) => 'user-and-role-management',
            $this->startsWithAny($path, ['settings', 'audit-logs']) => 'system-administration',
            $this->startsWithAny($path, ['bookings', 'booking-calendar', 'quotations', 'calendar-events']) => 'orders-and-events',
            $this->startsWithAny($path, ['payments', 'invoices', 'deposits', 'financial-reports']) => 'billing-and-payments',
            $this->startsWithAny($path, ['refunds']) => 'refund-management',
            $this->startsWithAny($path, ['customers', 'customer-messages']) => 'customer-management',
            $this->startsWithAny($path, ['inventory', 'inventory-history', 'ingredients', 'products', 'equipment', 'suppliers', 'shopping-list', 'delivery-zones']) => 'inventory-management',
            $this->startsWithAny($path, ['employees', 'departments', 'positions', 'salary-grades', 'schedules', 'attendance', 'daily-attendance', 'employee-requests', 'leave-requests', 'shift-types']) => 'people-and-staff-management',
            $this->startsWithAny($path, ['payroll', 'payslips']) => 'payroll',
            $this->startsWithAny($path, ['reports']) => 'reports',
            default => 'administrator-only',
        };
    }

    private function relativeApiPath(Request $request): string
    {
        $path = trim($request->path(), '/');
        return preg_replace('#^api/v1/?#', '', $path) ?? $path;
    }

    private function startsWithAny(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function matches(string $path, string $pattern): bool
    {
        return preg_match($pattern, $path) === 1;
    }

    private function normalizeRole(string $role): string
    {
        $normalized = strtolower(trim($role));
        return self::ROLE_ALIASES[$normalized] ?? str_replace('_', '-', $normalized);
    }
}