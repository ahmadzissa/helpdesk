<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = ['ticket_id', 'user_id', 'description'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
