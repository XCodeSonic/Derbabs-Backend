<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// Root route - shows API information
Route::get('/', function () {
    return response()->json([
        'app' => 'Catering Pro API',
        'version' => '1.0.0',
        'status' => 'online',
        'server_time' => now()->toDateTimeString(),
        'environment' => app()->environment(),
        'database' => DB::connection()->getDatabaseName(),
        'endpoints' => [
            'web' => [
                'root' => url('/'),
                'test' => url('/test'),
            ],
            'api' => [
                'test' => url('/api/test'),
                'health' => url('/api/health'),
                'v1' => [
                    'ping' => url('/api/v1/ping'),
                    'auth' => [
                        'send_otp' => url('/api/v1/auth/send-otp'),
                        'verify_otp' => url('/api/v1/auth/verify-otp'),
                        'login' => url('/api/v1/auth/login'),
                        'register' => url('/api/v1/auth/register'),
                    ]
                ]
            ]
        ],
        'documentation' => 'This is a REST API. Please use the endpoints above.'
    ]);
});

// Simple test route
Route::get('/test', function () {
    return response()->json([
        'success' => true,
        'message' => 'Web route is working!',
        'url' => url('/')
    ]);
});

// HTML welcome page (optional)
Route::get('/welcome', function () {
    return view('welcome');
});
