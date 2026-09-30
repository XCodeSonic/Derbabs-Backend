<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class MaintenanceModeMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = filter_var(
            Setting::getValue('system_maintenance', 'maintenance_mode', false),
            FILTER_VALIDATE_BOOLEAN
        );

        if (! $enabled) {
            return $next($request);
        }

        $user = $request->user();

        if ($user && $user->roles()->where('is_active', true)->whereIn('slug', ['super-admin', 'super_admin', 'superadmin'])->exists()) {
            return $next($request);
        }

        $path = trim($request->path(), '/');
        $path = preg_replace('#^api/v1/?#', '', $path) ?? $path;

        $allowedPrefixes = ['health', 'ping', 'auth', 'public', 'announcements'];

        foreach ($allowedPrefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return $next($request);
            }
        }

        $message = Setting::getValue(
            'system_maintenance',
            'maintenance_message',
            'We are performing scheduled maintenance. Please try again shortly.'
        );

        return response()->json([
            'success' => false,
            'message' => $message,
            'error_code' => 'MAINTENANCE_MODE',
        ], 503);
    }
}