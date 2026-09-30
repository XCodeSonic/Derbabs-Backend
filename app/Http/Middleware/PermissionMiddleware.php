<?php
namespace App\Http\Middleware;use Closure;use Illuminate\Http\Request;class PermissionMiddleware{public function handle(Request $request,Closure $next,string $permission){$user=$request->user();if(!$user||(!$user->hasRole('super-admin')&&!$user->hasPermission($permission)))return response()->json(['success'=>false,'message'=>'Forbidden permission.'],403);return $next($request);}}
