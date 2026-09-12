<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Mailbox extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'team_id', 'color', 'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password', 'imap_host', 'imap_port', 'imap_username', 'imap_password', 'sending_enabled', 'incoming_enabled', 'imap_encryption', 'last_synced_at', 'sync_status', 'sync_error'];

    protected function casts(): array
    {
        return ['smtp_password' => 'encrypted', 'imap_password' => 'encrypted', 'sending_enabled' => 'boolean', 'incoming_enabled' => 'boolean', 'last_synced_at' => 'datetime',
            'import_started_at' => 'datetime', 'imap_uidvalidity' => 'integer', 'imap_last_uid' => 'integer'];
    }

    protected static function booted(): void
    {
        static::creating(function (Mailbox $mailbox): void {
            $mailbox->import_started_at ??= $mailbox->created_at ?? now();
        });
    }

    protected $hidden = ['smtp_password', 'imap_password'];

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
