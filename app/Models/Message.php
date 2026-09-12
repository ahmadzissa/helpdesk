<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class Message extends Model
{
    use HasFactory;

    protected $hidden = ['email_html'];

    protected $fillable = ['ticket_id', 'user_id', 'kind', 'author_name', 'author_email', 'body', 'delivery', 'delivery_error', 'rule_name', 'attachments', 'mailbox_id', 'external_id', 'sending_epoch', 'attempt_id', 'original_body', 'translated_subject', 'translation_context'];

    public static function renderBody(string $body, string $kind): string
    {
        if ($kind === 'inbound') {
            return nl2br(e($body));
        }

        return Str::markdown($body, ['html_input' => 'strip', 'allow_unsafe_links' => false, 'renderer' => ['soft_break' => '<br />']]);
    }

    protected function authorEmail(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    protected function casts(): array
    {
        return ['attachments' => 'array', 'translation_context' => 'array'];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
