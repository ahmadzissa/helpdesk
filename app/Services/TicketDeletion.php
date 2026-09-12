<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class TicketDeletion
{
    public function pruneConversations(Builder $eligible): int
    {
        $eligible = (clone $eligible)->whereNull('merged_into_id');
        $deleted = 0;

        foreach ((clone $eligible)->select('id')->lazyById(100) as $candidate) {
            $deleted += DB::transaction(function () use ($eligible, $candidate): int {
                $ticket = (clone $eligible)->whereKey($candidate->id)->lockForUpdate()->first();
                if (! $ticket) {
                    return 0;
                }

                return $this->delete(Ticket::where(function (Builder $query) use ($ticket): void {
                    $query->whereKey($ticket->id)->orWhere('merged_into_id', $ticket->id);
                }));
            }, 5);
        }

        return $deleted;
    }

    public function delete(Builder $query): int
    {
        return DB::transaction(function () use ($query): int {
            $ids = (clone $query)->reorder('id')->lockForUpdate()->pluck('id')->all();
            if ($ids === []) {
                return 0;
            }
            $tickets = Ticket::whereKey($ids);
            $ticketIds = (clone $tickets)->select('id');
            $tickets->update(['merged_into_id' => null]);
            $paths = [];
            foreach (Message::whereIn('ticket_id', $ticketIds)->select('id', 'attachments')->lazyById(200) as $message) {
                foreach ($message->attachments ?? [] as $attachment) {
                    $paths[] = $attachment['path'] ?? null;
                }
            }
            foreach (DB::table('inline_images')->whereIn('ticket_id', $ticketIds)->select('id', 'path')->lazyById(200) as $image) {
                $paths[] = $image->path;
            }
            $paths = array_values(array_unique(array_filter($paths, fn ($path) => is_string($path) && preg_match('#\A(?:ticket-attachments|inline-images)/[A-Za-z0-9-]+(?:\.[A-Za-z0-9]+)?\z#', $path))));
            $deleted = $tickets->delete();
            if ($paths !== []) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($paths));
            }

            return $deleted;
        });
    }
}
