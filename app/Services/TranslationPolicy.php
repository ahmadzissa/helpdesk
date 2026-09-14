<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Ticket;
use App\Models\WorkspaceSetting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class TranslationPolicy
{
    public const HOLD_REASON = 'Browser translation required. Open this reply, translate it, and review the preview before sending.';

    public const LANGUAGE_RULE = 'regex:/^[a-z]{2,3}(-[A-Za-z0-9]{2,8}){0,2}$/';

    public function settings(): array
    {
        return array_replace(['incoming' => true, 'target' => 'en', 'outgoing' => false, 'auto_send' => false, 'revision' => 0], WorkspaceSetting::find('translation')?->value ?? []);
    }

    public function browserKey(): string
    {
        $stored = WorkspaceSetting::find('translation_key')?->value;

        return $stored ? Crypt::decryptString($stored['encrypted']) : (string) config('services.google_translation.browser_key', '');
    }

    public function customer(Ticket $ticket): ?object
    {
        return DB::table('customer_languages')->where('email', $ticket->requester_email)->first();
    }

    public function latestInbound(Ticket $ticket): ?Message
    {
        return Message::where('kind', 'inbound')->where('author_email', $ticket->requester_email)
            ->orderByDesc('created_at')->orderByDesc('id')->first();
    }

    public function detect(Message $message, ?string $language): void
    {
        if (! $language || $message->kind !== 'inbound' || mb_strtolower(trim($message->author_email ?? '')) !== $message->ticket->requester_email) {
            return;
        }
        $email = $message->ticket->requester_email;
        DB::table('customer_languages')->insertOrIgnore(['email' => $email, 'created_at' => now(), 'updated_at' => now()]);
        $current = DB::table('customer_languages')->where('email', $email)->lockForUpdate()->first();
        if (! $current->manual && $this->latestInbound($message->ticket)?->id === $message->id) {
            DB::table('customer_languages')->where('email', $email)->update(['language' => $language, 'source_message_id' => $message->id, 'updated_at' => now()]);
        }
    }

    public function context(Ticket $ticket): array
    {
        $settings = $this->settings();
        $customer = $this->customer($ticket);
        $cc = array_map(fn ($email) => mb_strtolower(trim($email)), $ticket->cc ?? []);
        sort($cc);

        return ['recipient' => $ticket->requester_email, 'cc' => array_values(array_unique($cc)),
            'target' => $customer?->language, 'subject_hash' => hash('sha256', $ticket->subject),
            'revision' => $settings['revision'], 'inbound_id' => $this->latestInbound($ticket)?->id];
    }

    public function rules(): array
    {
        return ['send_original' => 'sometimes|boolean', 'translation' => 'nullable|array:original_body,subject,source_language,context',
            'translation.original_body' => 'required_with:translation|string|max:50000',
            'translation.subject' => ['required_with:translation', 'string', 'max:500', 'not_regex:/[\r\n]/'],
            'translation.source_language' => ['required_with:translation', 'string', self::LANGUAGE_RULE],
            'translation.context' => 'required_with:translation|array'];
    }

    /** The browser submits the reviewed result; the server only checks its context and stores it. */
    public function outgoing(Ticket $ticket, string $body, ?array $translation, bool $sendOriginal = false): array
    {
        $required = $this->settings()['outgoing'];
        if ($sendOriginal) {
            abort_if($required, 422, 'Translate this reply into the customer language before sending.');
            abort_if($translation !== null, 422, 'Choose either the translated reply or the original reply.');

            return ['original_body' => $body, 'translated_subject' => null,
                'translation_context' => [...$this->context($ticket), 'send_original' => true,
                    'body_hash' => hash('sha256', $body), 'original_hash' => hash('sha256', $body)]];
        }
        if (! $translation) {
            abort_if($required, 422, 'Translate and review this reply in the browser before sending.');

            return [];
        }
        $context = $this->context($ticket);
        abort_unless($context['target'], 422, 'Detect or select the customer language before translating.');
        abort_unless($this->detectionCurrent($ticket, $context), 409, 'A new customer email needs language detection. Refresh and translate again.');
        abort_unless($translation['context'] == $context, 409, 'The recipient, language, subject, or settings changed. Translate this reply again.');
        $sameLanguage = strcasecmp($translation['source_language'], $context['target']) === 0;
        abort_if($sameLanguage && $body !== $translation['original_body'], 422, 'Send the unchanged original reply when its language matches the customer language.');
        $this->assertProtectedContent($translation['original_body'], $body);

        return ['original_body' => $translation['original_body'], 'translated_subject' => $translation['subject'],
            'translation_context' => [...$context, 'source' => $translation['source_language'], 'same_language' => $sameLanguage,
                'body_hash' => hash('sha256', $body), 'original_hash' => hash('sha256', $translation['original_body'])]];
    }

    private function assertProtectedContent(string $original, string $translated): void
    {
        $pattern = '~https?://[^\s<>"\x27)]+|/api/v1/inline-images/[a-f0-9-]{36}|[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}~iu';
        preg_match_all($pattern, $original, $before);
        preg_match_all($pattern, $translated, $after);
        sort($before[0]);
        sort($after[0]);
        abort_unless($before[0] === $after[0], 422, 'Translation changed a link, email address, or inline image. Review and translate again.');
    }

    public function ready(Message $message): bool
    {
        $context = $message->translation_context;
        if ($this->settings()['outgoing'] && (! $context || ! empty($context['send_original']) || empty($context['target']))) {
            return false;
        }
        if (! $context) {
            return ! $this->settings()['outgoing'];
        }
        $expected = $this->context($message->ticket);
        if (empty($context['send_original']) && ! $this->detectionCurrent($message->ticket, $expected)) {
            return false;
        }
        foreach ($expected as $key => $value) {
            if (! empty($context['send_original']) && in_array($key, ['target', 'inbound_id'], true)) {
                continue;
            }
            if (($context[$key] ?? null) !== $value) {
                return false;
            }
        }

        return ($context['body_hash'] ?? '') === hash('sha256', $message->body)
            && ($context['original_hash'] ?? '') === hash('sha256', $message->original_body ?? '');
    }

    public function holdIfNeeded(Message $message): void
    {
        if ($message->kind === 'outbound' && ! $this->ready($message)) {
            $message->update(['delivery' => 'translation_pending', 'delivery_error' => self::HOLD_REASON]);
        }
    }

    private function detectionCurrent(Ticket $ticket, array $context): bool
    {
        $customer = $this->customer($ticket);

        return $customer?->manual || $customer?->source_message_id === $context['inbound_id'];
    }
}
