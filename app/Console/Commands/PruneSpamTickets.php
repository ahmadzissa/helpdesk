<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Services\TicketDeletion;
use Illuminate\Console\Command;

class PruneSpamTickets extends Command
{
    protected $signature = 'tickets:prune-spam';

    protected $description = 'Permanently delete conversations that have been in Spam for 30 days';

    public function handle(TicketDeletion $deletion): int
    {
        $eligible = Ticket::where('folder', 'spam')->where('spammed_at', '<=', now()->subDays(30));
        $deleted = $deletion->pruneConversations($eligible);

        $this->info('Permanently deleted '.$deleted.' ticket(s) after 30 days in Spam.');

        return self::SUCCESS;
    }
}
