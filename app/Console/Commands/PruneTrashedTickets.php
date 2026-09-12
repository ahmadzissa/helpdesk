<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\TicketDeletion;
use Illuminate\Console\Command;

class PruneTrashedTickets extends Command
{
    protected $signature = 'tickets:prune-trash';

    protected $description = 'Permanently delete conversations that have been in Trash for seven days';

    public function handle(TicketDeletion $deletion): int
    {
        $eligible = Ticket::where('folder', 'trash')->where('trashed_at', '<=', now()->subDays(7));
        $deleted = $deletion->pruneConversations($eligible);

        $this->info('Permanently deleted '.$deleted.' ticket(s) after seven days in Trash.');

        return self::SUCCESS;
    }
}
