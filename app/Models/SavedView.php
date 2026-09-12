<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedView extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'tag', 'filters'];

    protected function casts(): array
    {
        return ['filters' => 'array'];
    }
}
