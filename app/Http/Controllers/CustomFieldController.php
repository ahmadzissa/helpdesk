<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\WorkspaceSetting;
use App\Services\TicketCustomFields;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CustomFieldController extends Controller
{
    public function index(TicketCustomFields $fields): JsonResponse
    {
        return response()->json($fields->settings());
    }

    public function update(Request $request): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        $data = $request->validate(['revision' => 'required|integer|min:0', 'fields' => 'present|array|max:20',
            'fields.*' => 'required|array:key,name', 'fields.*.key' => 'required|uuid|distinct',
            'fields.*.name' => 'required|string|max:80|distinct:ignore_case']);
        $settings = DB::transaction(function () use ($data, $request): array {
            DB::table('workspace_settings')->insertOrIgnore(['key' => 'custom_fields', 'value' => json_encode(['revision' => 0, 'fields' => []]), 'created_at' => now(), 'updated_at' => now()]);
            $setting = WorkspaceSetting::whereKey('custom_fields')->lockForUpdate()->firstOrFail();
            abort_unless($setting->value['revision'] === $data['revision'], 409, 'Custom fields changed in another window. Reload the settings before saving.');
            $value = ['revision' => $data['revision'] + 1, 'fields' => array_values($data['fields'])];
            $setting->update(['value' => $value]);
            Activity::create(['user_id' => $request->user()->id, 'description' => 'Updated workspace custom fields.']);

            return $value;
        });

        return response()->json($settings);
    }
}
