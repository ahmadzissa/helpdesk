<?php

namespace App\Jobs;

use App\Models\Mailbox;
use App\Models\User;
use App\Services\MailSafety;
use App\Services\OutgoingMail;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Throwable;

class SendPasswordRecovery implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public string $attemptId;

    public function __construct(public int $userId, public int $mailboxId, public string $token, public int $epoch)
    {
        $this->attemptId = (string) Str::uuid();
    }

    public function handle(): void
    {
        $user = User::find($this->userId);
        $mailbox = Mailbox::find($this->mailboxId);
        if (! $user || ! $mailbox || ! Password::tokenExists($user, $this->token)) {
            return;
        }
        if (! app(MailSafety::class)->beginRecovery($mailbox, $user->email, $this->epoch, $this->attemptId)) {
            return;
        }
        try {
            $url = rtrim(config('app.url'), '/').'/reset-password?'.http_build_query(['token' => $this->token, 'email' => $user->email]);
            app(OutgoingMail::class)->mailer($mailbox)->raw("Reset your Relay password:\n\n".$url."\n\nThis link expires in 60 minutes. If you did not request this, ignore this email.", function (Message $mail) use ($user, $mailbox) {
                $attempt = DB::table('mail_delivery_attempts')->where('id', $this->attemptId)->firstOrFail();
                $mail->getSymfonyMessage()->getHeaders()->addIdHeader('Message-ID', $attempt->external_id);
                $mail->to($user->email)->from($mailbox->email, $mailbox->name)->subject('Reset your Relay password');
            });
            DB::table('mail_delivery_attempts')->where('id', $this->attemptId)->update(['sent_at' => now()]);
        } catch (Throwable $exception) {
            app(MailSafety::class)->recordFailure($this->attemptId, 'smtp', 'Password recovery email failed. Review the outgoing mailbox.');
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($this->attemptId) {
            app(MailSafety::class)->recordFailure($this->attemptId, 'smtp', 'Password recovery delivery could not be confirmed.');
        }
    }
}
