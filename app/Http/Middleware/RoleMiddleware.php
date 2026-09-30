<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    /**
     * Aliases used so route definitions can say `role:admin` while the
     * DB may store `administrator` / `owner` / `superadmin`, etc.
     * Kept in sync with RoleAccessMiddleware::ROLE_ALIASES.
     */
    private const ROLE_ALIASES = [
        'super_admin'       => 'super-admin',
        'superadmin'        => 'super-admin',
        'administrator'     => 'admin',
        'owner'             => 'admin',
        'finance'           => 'cashier',
        'finance-staff'     => 'cashier',
        'finance_staff'     => 'cashier',
        'people-manager'    => 'staff-manager',
        'people_manager'    => 'staff-manager',
        'staff_manager'     => 'staff-manager',
        'inventory_manager' => 'inventory-manager',
        'head_chef'         => 'head-chef',
    ];

    /**
     * Canonical super-admin slugs that always bypass role checks.
     */
    private const SUPER_ADMIN_SLUGS = [
        'super-admin',
        'super_admin',
        'superadmin',
    ];

    /**
     * Handle an incoming request.
     *
     * Usage:
     *   ->middleware('role:admin')
     *   ->middleware('role:admin,cashier')
     *   ->middleware('role:super-admin')
     *   ->middleware('role')                 // any recognized role is fine
     */
    public function handle(Request $request, Closure $next, ...$roles): Response
    {
        $user = Auth::user();

        if (! $user) {
            return response()->json([
                'success'    => false,
                'message'    => 'Unauthenticated. Please log in.',
                'error_code' => 'UNAUTHENTICATED',
            ], 401);
        }

        $userRoles = $user->roles()
            ->where('is_active', true)
            ->pluck('slug')
            ->map(fn($slug) => $this->normalizeRole((string) $slug))
            ->unique()
            ->values()
            ->all();

        // Super-admin passes every role check.
        if (array_intersect($userRoles, self::SUPER_ADMIN_SLUGS) !== []) {
            return $next($request);
        }

        // No explicit roles requested → any recognized role is accepted.
        if ($roles === []) {
            if ($userRoles !== []) {
                return $next($request);
            }

            return response()->json([
                'success'    => false,
                'message'    => 'Forbidden. No active role assigned to this account.',
                'error_code' => 'NO_ROLE',
            ], 403);
        }

        $requiredRoles = collect($roles)
            ->map(fn($slug) => $this->normalizeRole((string) $slug))
            ->unique()
            ->values()
            ->all();

        if (array_intersect($userRoles, $requiredRoles) !== []) {
            return $next($request);
        }

        return response()->json([
            'success'        => false,
            'message'        => 'Unauthorized. You do not have permission to access this resource.',
            'error_code'     => 'ROLE_MISMATCH',
            'required_roles' => $requiredRoles,
        ], 403);
    }

    private function normalizeRole(string $role): string
    {
        $normalized = strtolower(trim($role));

        return self::ROLE_ALIASES[$normalized] ?? str_replace('_', '-', $normalized);
    }
}