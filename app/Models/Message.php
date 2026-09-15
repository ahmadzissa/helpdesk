<?php

namespace App\Models;

use App\Services\TicketCustomFields;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\DefaultAttributes\DefaultAttributesExtension;

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

        return Str::markdown($body, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
            'renderer' => ['soft_break' => '<br />'],
            'autolink' => ['default_protocol' => 'https'],
            'default_attributes' => ['attributes' => [Link::class => [
                'style' => 'color:#0057d9;text-decoration:underline',
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
            ]]],
        ], [new DefaultAttributesExtension]);
    }

    protected function authorEmail(): Attribute
    {
        return Attribute::make(set: fn (?string $value) => $value === null ? null : mb_strtolower(trim($value)));
    }

    protected function casts(): array
    {
        return ['attachments' => 'array', 'translation_context' => 'array', 'sent_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::created(function (Message $message): void {
            Ticket::whereKey($message->ticket_id)->update(['workflow_activity_at' => now()]);
            $ticket = Ticket::find($message->ticket_id);
            if ($ticket) {
                app(TicketCustomFields::class)->fillShopifyDomain($ticket, [$ticket->subject, $message->body, $message->email_html ?? '']);
            }
        });
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }
}
