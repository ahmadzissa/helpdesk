<?php

namespace App\Console\Commands;

use App\Jobs\SyncMailbox;
use App\Models\Mailbox;
use Illuminate\Console\Command;

class SyncMailboxes extends Command
{
    protected $signature = 'mailboxes:sync {--mailbox= : Sync one mailbox ID} {--inline : Fetch incoming mail now without a queue worker}';

    protected $description = 'Queue incoming email synchronization for enabled mailboxes';

    public function handle(): int
    {
        $query = Mailbox::where('incoming_enabled', true);
        if ($this->option('mailbox')) {
            $query->whereKey($this->option('mailbox'));
        }
        $count = 0;
        foreach ($query->get() as $mailbox) {
            if ($this->option('inline')) {
                SyncMailbox::dispatchSync($mailbox->id);
            } else {
                SyncMailbox::dispatch($mailbox->id);
            }
            $count++;
        }
        $this->info($count.($this->option('inline') ? ' mailbox synchronization check(s) completed.' : ' mailbox synchronization job(s) queued.'));

        return self::SUCCESS;
    }
}
