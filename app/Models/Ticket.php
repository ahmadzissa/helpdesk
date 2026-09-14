<?php

namespace App\Models;

use App\Services\EmailHeaders;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Ticket extends Model
{
    use HasFactory;

    protected $fillable = ['subject', 'requester_name', 'requester_email', 'company', 'status', 'priority', 'folder', 'source', 'mailbox_id', 'team_id', 'assignee_id', 'tags', 'cc', 'custom_fields', 'translation_enabled', 'unread', 'last_activity_at', 'resolved_at'];

    protected $hidden = ['normalized_tags'];

    protected $attributes = ['translation_enabled' => true];

    protected function subject(): Attribute
    {
        return Attribute::make(
            get: fn (string $value): string => app(EmailHeaders::class)->decode($value),
            set: fn (string $value): string => app(EmailHeaders::class)->decode($value),
        );
    }

    protected static function booted(): void
    {
        static::saving(function (Ticket $ticket): void {
            if (! $ticket->exists || $ticket->isDirty('status')) {
                $ticket->status_changed_at = now();
            }
            if (! $ticket->exists || $ticket->isDirty(['subject', 'requester_name', 'requester_email', 'company', 'status', 'priority', 'folder', 'source', 'mailbox_id', 'team_id', 'assignee_id', 'tags', 'cc', 'custom_fields'])) {
                $ticket->workflow_activity_at = now();
            }
            if (! $ticket->exists || $ticket->isDirty('folder')) {
                $ticket->trashed_at = $ticket->folder === 'trash' ? now() : null;
                $ticket->spammed_at = $ticket->folder === 'spam' ? now() : null;
                if ($ticket->exists && $ticket->folder === 'inbox') {
                    $ticket->last_activity_at = now();
                }
            }
        });
    }

    public function assertWritable(): void
    {
        abort_if($this->merged_into_id !== null, 409, 'This ticket was merged into #'.$this->merged_into_id.'. Open the main conversation to make changes.');
    }

    public function setTagsAttribute(?array $tags): void
    {
        $tags = array_values($tags ?? []);
        $this->attributes['tags'] = json_encode($tags);
        $this->attributes['normalized_tags'] = json_encode(array_map(fn ($tag) => mb_strtolower($tag), $tags));
    }

    public function setRequesterEmailAttribute(string $email): void
    {
        $this->attributes['requester_email'] = mb_strtolower(trim($email));
    }

    protected function casts(): array
    {
        return ['tags' => 'array', 'cc' => 'array', 'custom_fields' => 'array', 'unread' => 'boolean', 'translation_enabled' => 'boolean', 'last_activity_at' => 'datetime', 'resolved_at' => 'datetime', 'trashed_at' => 'datetime', 'spammed_at' => 'datetime', 'status_changed_at' => 'datetime', 'workflow_activity_at' => 'datetime'];
    }

    public const STATUSES = ['Open', 'Pending', 'On hold', 'Solved', 'Closed'];

    public const PRIORITIES = ['Low', 'Normal', 'High', 'Urgent'];

    public const ARCHIVE_AFTER_DAYS = 60;

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class)->orderBy('id');
    }

    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function scopeInInbox(Builder $query): void
    {
        $query->where('folder', 'inbox')->whereNull('merged_into_id')->where('last_activity_at', '>', now()->subDays(self::ARCHIVE_AFTER_DAYS));
    }

    public function scopeAwaitingArchive(Builder $query): void
    {
        $query->where('folder', 'inbox')->whereNull('merged_into_id')->where('last_activity_at', '<=', now()->subDays(self::ARCHIVE_AFTER_DAYS));
    }

    public function scopeMatching(Builder $query, array $filters, ?string $tag = null): void
    {
        if ($tag) {
            $query->whereJsonContains('normalized_tags', mb_strtolower($tag));
        }
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (! in_array($key, ['status', 'priority', 'assignee_id', 'team_id', 'source'])) {
                $query->whereRaw('1 = 0');

                continue;
            }
            if ($key === 'assignee_id' && (string) $value === 'unassigned') {
                $query->whereNull('assignee_id');
            } else {
                $query->where($key, $value);
            }
        }
    }
}
