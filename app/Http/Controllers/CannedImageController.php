<?php

namespace App\Http\Controllers;

use App\Services\CannedImages;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CannedImageController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $request->validate(['image' => 'required|file|image|mimes:jpg,jpeg,png,gif,webp|max:5120|dimensions:max_width=8000,max_height=8000']);
        $file = $request->file('image');
        $id = (string) Str::uuid();
        $path = $file->store('canned-images', 'local');
        abort_unless($path, 500, 'The image could not be saved. Please try again.');
        try {
            DB::table('canned_reply_images')->insert(['id' => $id, 'path' => $path, 'name' => mb_substr($file->getClientOriginalName(), 0, 200), 'mime' => $file->getMimeType(), 'created_at' => now()]);
        } catch (\Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return response()->json(['url' => '/api/v1/canned-images/'.$id, 'markdown' => '![Image](/api/v1/canned-images/'.$id.')'], 201);
    }

    public function show(string $id): StreamedResponse
    {
        $image = DB::table('canned_reply_images')->where('id', $id)->first();
        abort_unless($image && Storage::disk('local')->exists($image->path), 404);

        return Storage::disk('local')->response($image->path, $image->name, ['Content-Type' => $image->mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store', 'Content-Security-Policy' => "default-src 'none'; sandbox"], 'inline');
    }

    public function destroy(string $id): JsonResponse
    {
        return response()->json(['deleted' => app(CannedImages::class)->deleteUnused($id)]);
    }
}
