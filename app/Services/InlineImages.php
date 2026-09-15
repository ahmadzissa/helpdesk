<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Support\Facades\DB;

class InlineImages
{
    public function ids(string $body): array
    {
        preg_match_all('~/api/v1/inline-images/([a-f0-9-]{36})~i', $body, $matches);

        return array_values(array_unique($matches[1]));
    }

    public function bind(Message $message, Ticket $ticket, int $userId): void
    {
        app(CannedImages::class)->images($message->body);
        $ids = $this->ids($message->body);
        abort_if(count($ids) > 10, 422, 'Use up to 10 inline images per message.');
        foreach ($ids as $id) {
            $image = DB::table('inline_images')->where('id', $id)->lockForUpdate()->first();
            abort_unless($image && $image->ticket_id === $ticket->id && $image->user_id === $userId && ($image->message_id === null || $image->message_id === $message->id), 422, 'An inline image is unavailable. Upload it again for this reply.');
            DB::table('inline_images')->where('id', $id)->update(['message_id' => $message->id]);
        }
    }
}
