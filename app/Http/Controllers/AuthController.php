<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WorkspaceSetting;
use App\Services\MobilePush;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class AuthController extends Controller
{
    public function session(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user(), 'needs_setup' => WorkspaceSetting::needsSetup(), 'csrf_token' => csrf_token()])
            ->header('Cache-Control', 'no-store, private');
    }

    public function setup(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:100', 'email' => 'required|email|max:255|unique:users',
            'password' => ['required', 'confirmed', Password::min(12)],
            'workspace_name' => 'required|string|max:100', 'sample_data' => 'boolean',
        ]);
        $user = Cache::lock('workspace-setup', 10)->block(3, function () use ($data) {
            return DB::transaction(function () use ($data) {
                abort_unless(WorkspaceSetting::needsSetup(), 409, 'This workspace is already set up.');
                $user = User::create([...collect($data)->only(['name', 'email', 'password'])->all(), 'role' => 'admin']);
                if ($data['sample_data'] ?? false) {
                    app(DatabaseSeeder::class)->run();
                }
                $settings = array_replace(['timezone' => 'Asia/Riyadh'], WorkspaceSetting::find('general')?->value ?? [], [
                    'name' => $data['workspace_name'], 'registration_enabled' => false,
                ]);
                WorkspaceSetting::updateOrCreate(['key' => 'general'], ['value' => $settings]);

                return $user;
            });
        });
        Auth::login($user);
        $request->session()->regenerate();

        return $request->is('api/*')
            ? response()->json(['user' => $user, 'csrf_token' => csrf_token()], 201)
            : to_route('tickets.index');
    }

    public function login(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string']);
        if (! Auth::attempt($data, $request->boolean('remember'))) {
            throw ValidationException::withMessages(['email' => 'The email or password is incorrect.']);
        }
        $request->session()->regenerate();

        $view = $request->user()->preferences['initial_view'] ?? 'all';

        return $request->is('api/*')
            ? response()->json(['user' => $request->user(), 'csrf_token' => csrf_token()])
            : redirect()->intended(route('tickets.index', $view === 'all' ? [] : ['view' => $view]));
    }

    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        app(MobilePush::class)->revokeSession($request);
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if (! $request->is('api/*')) {
            Inertia::clearHistory();
        }

        return $request->is('api/*')
            ? response()->json(['csrf_token' => csrf_token()])
            : to_route('login');
    }

    public function profile(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'sometimes|required|string|max:100',
            'email' => ['sometimes', 'required', 'email', Rule::unique('users')->ignore($request->user()->id)],
            'current_password' => 'required_with:password|current_password',
            'password' => ['sometimes', 'required', 'confirmed', Password::min(12)],
            'preferences' => 'sometimes|array:theme,colors,initial_view,source_indicators,signature',
            'preferences.theme' => 'sometimes|in:helpdesk,orchid,ocean,forest,ember,slate,custom',
            'preferences.colors' => 'sometimes|array:accent,rail,background,surface',
            'preferences.colors.*' => ['required', 'regex:/^#[a-fA-F0-9]{6}$/'],
            'preferences.initial_view' => 'sometimes|in:all,mine,unassigned,Open,Pending,On hold,Solved,Closed,unread,undelivered',
            'preferences.source_indicators' => 'sometimes|boolean',
            'preferences.signature' => 'nullable|string|max:2000',
        ]);
        if (isset($data['preferences'])) {
            $data['preferences'] = array_replace($request->user()->preferences ?? [], $data['preferences']);
        }
        $request->user()->update(collect($data)->except(['current_password'])->all());

        return response()->json($request->user()->fresh());
    }
}
