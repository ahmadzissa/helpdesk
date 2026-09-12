<?php

namespace App\Http\Controllers;

use App\Jobs\SendPasswordRecovery;
use App\Models\Mailbox;
use App\Models\User;
use App\Models\WorkspaceSetting;
use App\Services\MailSafety;
use App\Services\SenderPolicy;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class RecoveryController extends Controller
{
    public function forgot(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255']);
        $user = User::whereRaw('LOWER(email) = ?', [mb_strtolower($data['email'])])->first();
        $mailbox = Mailbox::find(WorkspaceSetting::find('mail_policy')?->value['recovery_mailbox_id'] ?? 0);
        $state = app(MailSafety::class)->status();
        if ($user && $mailbox?->sending_enabled && $mailbox->smtp_host && ! $state['paused'] && ! app(SenderPolicy::class)->restriction($user->email)) {
            Password::sendResetLink(['email' => $user->email], function (User $user, string $token) use ($mailbox, $state) {
                SendPasswordRecovery::dispatch($user->id, $mailbox->id, $token, (int) $state['epoch'])->afterCommit();
            });
        }

        $message = 'If this account is eligible and outgoing email is available, a password reset link will arrive shortly.';

        return $request->is('api/*') ? response()->json(['message' => $message]) : back()->with('notice', $message);
    }

    public function reset(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'token' => 'required|string|max:255', 'password' => ['required', 'confirmed', PasswordRule::min(12)]]);
        $status = Password::reset($data, function (User $user, string $password) {
            DB::transaction(function () use ($user, $password) {
                $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
            });
            event(new PasswordReset($user));
        });
        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages(['email' => 'This reset link is invalid or expired. Request a new link.']);
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        if (! $request->is('api/*')) {
            Inertia::clearHistory();
        }

        $message = 'Password updated. Sign in with your new password.';

        return $request->is('api/*')
            ? response()->json(['message' => $message, 'csrf_token' => csrf_token()])
            : to_route('login')->with('notice', $message);
    }
}
