<?php

namespace App\Http\Middleware;

use App\Http\Controllers\WorkspaceController;
use App\Models\WorkspaceSetting;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => fn () => ['user' => $request->user()],
            'needs_setup' => fn () => WorkspaceSetting::needsSetup(),
            'csrf_token' => fn () => csrf_token(),
            'notice' => fn () => $request->session()->get('notice'),
            'base_path' => $request->getBaseUrl(),
            'navigation' => fn () => [
                'path' => '/'.ltrim($request->path(), '/'),
                'query' => (object) $request->query(),
                'params' => (object) $request->route()->parameters(),
            ],
            'workspace' => fn () => $request->user()
                ? app(WorkspaceController::class)->bootstrap($request)->getData(true)
                : null,
        ];
    }
}
