<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WorkspaceSetting extends Model
{
    use HasFactory;

    protected $fillable = ['key', 'value'];

    public static function registrationEnabled(): bool
    {
        return (static::find('general')?->value['registration_enabled'] ?? false) === true;
    }

    public static function needsSetup(): bool
    {
        return ! User::exists();
    }

    protected function casts(): array
    {
        return ['value' => 'array'];
    }

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';
}
