<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class InlineImages
{
    public function ids(string $body): array
    {
        preg_match_all('~<img\b[^>]*\bsrc="/api/v1/inline-images/([a-f0-9-]{36})"~i', Message::renderBody($body, 'outbound'), $matches);

        return array_values(array_unique($matches[1]));
    }

    /** @return array<string, object> */
    public function linkedImages(string $body, ?int $userId): array
    {
        $html = Message::renderBody($body, 'outbound');
        preg_match_all('~<img\b[^>]*\bsrc="([^"]+)"~i', $html, $matches);
        $prefix = preg_quote(rtrim(config('app.url'), '/').'/api/v1/inline-images/', '~');
        $images = [];
        foreach (array_unique($matches[1]) as $source) {
            $url = html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (! preg_match('~\A'.$prefix.'([a-f0-9-]{36})\z~i', $url, $id)) {
                continue;
            }
            $image = DB::table('inline_images')->where('id', strtolower($id[1]))->first();
            abort_unless($image && ($image->message_id || ($userId !== null && $image->user_id === $userId))
                && Storage::disk('local')->exists($image->path), 422, 'An image link is unavailable. Choose another image.');
            $images[$source] = $image;
        }

        return $images;
    }

    public function bind(Message $message, Ticket $ticket, int $userId): void
    {
        app(CannedImages::class)->images($message->body);
        $ids = $this->ids($message->body);
        $linked = $this->linkedImages($message->body, $userId);
        abort_if(count(array_unique([...$ids, ...array_column($linked, 'id')])) > 10, 422, 'Use up to 10 inline images per message.');
        foreach ($ids as $id) {
            $image = DB::table('inline_images')->where('id', $id)->lockForUpdate()->first();
            abort_unless($image && $image->ticket_id === $ticket->id && $image->user_id === $userId && ($image->message_id === null || $image->message_id === $message->id), 422, 'An inline image is unavailable. Upload it again for this reply.');
            DB::table('inline_images')->where('id', $id)->update(['message_id' => $message->id]);
        }
    }
}
