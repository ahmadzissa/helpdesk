<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CannedImages
{
    /** @return Collection<int, object> */
    public function images(string $body): Collection
    {
        preg_match_all('~/api/v1/canned-images/([a-f0-9-]{36})~i', $body, $matches);
        $ids = array_values(array_unique(array_map('strtolower', $matches[1])));
        if ($ids === []) {
            return collect();
        }
        abort_if(count($ids) > 10, 422, 'Use up to 10 images per response.');
        $images = DB::table('canned_reply_images')->whereIn('id', $ids)->get();
        abort_unless($images->count() === count($ids), 422, 'A canned response image is unavailable. Upload it again.');
        foreach ($images as $image) {
            abort_unless(Storage::disk('local')->exists($image->path), 422, 'A canned response image is unavailable. Upload it again.');
        }

        return $images;
    }
}
