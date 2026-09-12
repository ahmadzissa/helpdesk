<?php

namespace App\Services;

use App\Models\WorkspaceSetting;
use Illuminate\Validation\ValidationException;

class TicketCustomFields
{
    /** @return array{revision:int, fields:array<int, array{key:string, name:string}>} */
    public function settings(): array
    {
        return WorkspaceSetting::find('custom_fields')?->value ?? ['revision' => 0, 'fields' => []];
    }

    public function validateValues(array $values): void
    {
        $keys = array_column($this->settings()['fields'], 'key');
        if (array_diff(array_keys($values), $keys) !== []) {
            throw ValidationException::withMessages(['custom_fields' => 'Choose fields defined in Settings → Custom fields. Reload this ticket if a field was removed.']);
        }
    }
}
