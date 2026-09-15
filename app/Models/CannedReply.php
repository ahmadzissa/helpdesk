<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CannedReply extends Model
{
    use HasFactory;

    protected $fillable = ['title', 'shortcut', 'category', 'body'];

    /** @return list<string> */
    public static function shortcuts(string $value): array
    {
        return array_map(fn (string $shortcut): string => '#'.ltrim(trim(preg_replace('/\s+/u', ' ', $shortcut)), '#'), explode(',', $value));
    }
}
