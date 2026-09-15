<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class CannedImages
{
    /** @return list<string> */
    public function ids(string $body): array
    {
        $prefix = rtrim(config('app.url'), '/');
        $body = preg_replace_callback('~https?://[^\s<>"\x27]+~i', fn (array $match): string => str_starts_with($match[0], $prefix.'/api/v1/canned-images/') ? substr($match[0], strlen($prefix)) : '', $body);
        preg_match_all('~/api/v1/canned-images/([a-f0-9-]{36})~i', $body, $matches);

        return array_values(array_unique(array_map('strtolower', $matches[1])));
    }

    public function deleteUnused(string $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $image = DB::table('canned_reply_images')->where('id', $id)->lockForUpdate()->first();
            if (! $image) {
                return true;
            }
            foreach (['canned_replies' => ['body'], 'messages' => ['body', 'original_body', 'email_html'], 'ticket_drafts' => ['body'],
                'message_translations' => ['body'], 'follow_ups' => ['body'], 'automations' => ['actions'], 'macros' => ['actions']] as $table => $columns) {
                foreach ($columns as $column) {
                    if (DB::table($table)->where($column, 'like', '%'.$id.'%')->exists()) {
                        return false;
                    }
                }
            }
            $disk = Storage::disk('local');
            abort_if($disk->exists($image->path) && ! $disk->delete($image->path), 500, 'The image could not be deleted. Please try again.');
            DB::table('canned_reply_images')->where('id', $id)->delete();

            return true;
        });
    }

    /** @return Collection<int, object> */
    public function images(string $body): Collection
    {
        $ids = $this->ids($body);
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
