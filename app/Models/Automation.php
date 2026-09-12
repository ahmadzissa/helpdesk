<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Automation extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'enabled', 'trigger', 'conditions', 'actions', 'repeat_mode', 'interval_minutes', 'max_runs'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'conditions' => 'array', 'actions' => 'array'];
    }
}
