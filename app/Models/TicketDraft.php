<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TicketDraft extends Model
{
    use HasFactory;

    protected $fillable = ['ticket_id', 'user_id', 'body', 'private'];

    protected function casts(): array
    {
        return ['private' => 'boolean'];
    }
}
