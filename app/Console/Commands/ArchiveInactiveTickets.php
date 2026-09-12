<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

class ArchiveInactiveTickets extends Command
{
    protected $signature = 'tickets:archive-inactive {--dry-run : Count tickets due for archiving without changing them}';

    protected $description = 'Archive inbox conversations after 60 days without activity, across all statuses';

    public function handle(): int
    {
        $eligible = Ticket::awaitingArchive();
        if ($this->option('dry-run')) {
            $this->info($eligible->count().' conversation(s) are due for archiving.');

            return self::SUCCESS;
        }

        $archived = 0;
        (clone $eligible)->select('id')->chunkById(500, function (Collection $tickets) use ($eligible, &$archived): void {
            $archived += (clone $eligible)->whereKey($tickets->modelKeys())->update(['folder' => 'archive']);
        });

        $this->info('Archived '.$archived.' conversation(s) after 60 days without activity.');

        return self::SUCCESS;
    }
}
