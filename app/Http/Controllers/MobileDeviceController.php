<?php

namespace App\Http\Controllers;

use App\Services\MobilePush;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MobileDeviceController extends Controller
{
    public function __construct(private MobilePush $push) {}

    public function session(Request $request): JsonResponse
    {
        abort_unless(config('mobile.enabled'), 503, 'Mobile support is not configured.');
        $principal = $this->push->principal($request);

        return response()->json(['authenticated' => $principal && $this->push->allowed((int) $principal->id),
            'name' => $principal?->name, 'csrf_token' => csrf_token()])->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): JsonResponse
    {
        abort_unless(config('mobile.enabled'), 503);
        $principal = $this->push->principal($request);
        abort_unless($principal && $this->push->allowed((int) $principal->id), 403);
        $data = $request->validate(['installation_id' => 'required|uuid',
            'push_token' => ['required', 'string', 'max:255', 'regex:/^(ExponentPushToken|ExpoPushToken)\[[A-Za-z0-9_-]+\]$/']]);
        $device = $this->push->register($request, $principal, $data);

        return response()->json(['registered' => true, 'presence' => $this->push->presence($device)])->header('Cache-Control', 'no-store, private');
    }

    public function presence(Request $request): JsonResponse
    {
        abort_unless(config('mobile.enabled') && $this->push->isChat(), 404);
        $principal = $this->push->principal($request);
        abort_unless($principal && $this->push->allowed((int) $principal->id), 403);
        $data = $request->validate(['installation_id' => 'required|uuid', 'action' => 'required|in:online,offline,activity']);

        return response()->json($this->push->activity($request, $principal, $data['installation_id'], $data['action']))->header('Cache-Control', 'no-store, private');
    }
}
