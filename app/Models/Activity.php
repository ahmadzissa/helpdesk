<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = ['ticket_id', 'user_id', 'description'];

    protected function description(): Attribute
    {
        return Attribute::get(fn (string $value): string => preg_replace([
            '/^Created ticket #\d+$/',
            '/^(Updated .+|Automation “.*” ran) on #\d+$/u',
            '/^(Added private note|Replied) to #\d+$/',
            '/^(Received email|Bounce received|Outgoing delivery failed|Timed follow-up processed) for #\d+/',
            '/^(Applied macro “.*”) to #\d+$/u',
            '/^Merged #\d+ into this ticket\. Earlier messages remain in #\d+\.$/',
            '/^Merged into #\d+\./',
            '/^Scheduled follow-up #\d+ for #\d+$/',
            '/^Cancelled follow-up #\d+$/',
            '/^(Chose original language for reply|Prepared browser translation for reply) #\d+\.$/',
        ], [
            'Created ticket',
            '$1 on this ticket',
            '$1 to this ticket',
            '$1 for this ticket',
            '$1 to this ticket',
            'Merged another ticket into this conversation. Earlier messages remain in the original ticket.',
            'Merged into the main ticket.',
            'Scheduled follow-up for this ticket',
            'Cancelled follow-up',
            '$1.',
        ], $value));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
