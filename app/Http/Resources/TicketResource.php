<?php

namespace App\Http\Resources;

use App\Models\Message;
use App\Services\EmailContent;
use App\Services\TicketCustomFields;
use App\Services\TranslationPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $data = parent::toArray($request);
        if (isset($data['mailbox'])) {
            $data['mailbox'] = collect($data['mailbox'])->only(['id', 'name', 'email', 'color', 'team_id', 'sending_enabled', 'incoming_enabled'])->all();
        }
        if (isset($data['messages'])) {
            $recipients = array_map(fn (string $email): string => mb_strtolower(trim($email)), [$this->requester_email, ...($this->cc ?? [])]);
            $data['email_opt_outs'] = DB::table('recipient_suppressions')->whereIn('email', $recipients)->where('reason', 'opt_out')->pluck('email')->all();
            $data['custom_field_definitions'] = app(TicketCustomFields::class)->settings()['fields'];
            $policy = app(TranslationPolicy::class);
            $target = $policy->settings()['target'];
            $data['customer_language'] = $policy->customer($this->resource);
            $data['translation_context'] = $policy->context($this->resource);
            $data['subject_hash'] = hash('sha256', $data['subject']);
            $subject = DB::table('ticket_translations')->where('ticket_id', $this->id)->where('target_language', $target)->where('source_hash', $data['subject_hash'])->first();
            $data['subject_translation'] = $subject;
            $sample = $policy->latestInbound($this->resource);
            $content = app(EmailContent::class);
            $sampleFormat = $sample?->email_html ? 'html' : 'text';
            $data['language_sample'] = $sample ? ['id' => $sample->id, 'body' => $content->text($sample), 'source_hash' => hash('sha256', $sample->body),
                'translation_format' => $sampleFormat, 'translation_text' => $sampleFormat === 'html' ? $content->render($sample) : $content->text($sample)] : null;
            $translations = DB::table('message_translations')->whereIn('message_id', array_column($data['messages'], 'id'))->where('target_language', $target)->get()->keyBy('message_id');
            $models = $this->resource->messages->keyBy('id');
            $data['messages'] = array_map(function (array $message) use ($translations, $models, $content) {
                $model = $models->get($message['id']);
                $message['body_html'] = $content->render($model);
                $message['translation_format'] = $model->kind === 'inbound' && $model->email_html ? 'html' : 'text';
                $message['translation_text'] = $message['translation_format'] === 'html' ? $message['body_html'] : $content->text($model);
                $message['source_hash'] = hash('sha256', $message['body']);
                $message['original_body_html'] = isset($message['original_body']) ? Message::renderBody($message['original_body'], $message['kind']) : null;
                $translation = $translations->get($message['id']);
                if ($translation) {
                    $translation->body = $content->withoutTrackingLabels($translation->body, $model);
                }
                if ($translation && $translation->body_format !== $message['translation_format'] && str_contains($message['body_html'], '<img ')) {
                    $translation = null;
                }
                $message['translation'] = $translation && $translation->source_hash === $message['source_hash'] ? [...(array) $translation, 'body_html' => $content->render($model, $translation->body, $translation->body_format)] : null;
                if ($message['attempt_id'] ?? null) {
                    $attempt = DB::table('mail_delivery_attempts')->where('id', $message['attempt_id'])->first();
                    $message['sent_at'] = $attempt?->sent_at;
                    $message['delivered_at'] = $attempt?->delivered_at;
                    $message['opened_at'] = $attempt?->opened_at;
                    $message['failed_at'] = $attempt?->failed_at;
                }
                $inlineIndexes = $content->inlineAttachmentIndexes($model);
                $attachments = array_filter($message['attachments'] ?? [], fn (array $file, int $index): bool => ! in_array($index, $inlineIndexes, true), ARRAY_FILTER_USE_BOTH);
                $message['attachments'] = array_map(function (array $file, int $index) use ($message) {
                    $disk = Storage::disk('local');
                    $isImage = $disk->exists($file['path']) && in_array($disk->mimeType($file['path']), EmailContent::IMAGE_TYPES, true);
                    $parameters = ['message' => $message['id'], 'index' => $index];

                    return ['name' => $file['name'], 'size' => $file['size'], 'url' => route('attachments.download', $parameters),
                        'preview_url' => $isImage ? route('attachments.inline', $parameters) : null];
                }, $attachments, array_keys($attachments));

                return $message;
            }, $data['messages']);
        }
        unset($data['latest_message']['attachments']);
        if (($data['latest_message']['attempt_id'] ?? null) && ! array_key_exists('opened_at', $data['latest_message'])) {
            $attempt = DB::table('mail_delivery_attempts')->where('id', $data['latest_message']['attempt_id'])->first();
            $data['latest_message']['opened_at'] = $attempt?->opened_at;
            $data['latest_message']['delivered_at'] = $attempt?->delivered_at;
        }

        return $data;
    }
}
