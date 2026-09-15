<?php

namespace App\Console\Commands;

use App\Jobs\TranslateAutomatedReply;
use App\Models\Message;
use App\Models\Ticket;
use App\Services\AutomationEngine;
use App\Services\FollowUpRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunWorkflows extends Command
{
    protected $signature = 'helpdesk:automate';

    protected $description = 'Run due follow-ups and time-based ticket rules';

    public function handle(AutomationEngine $engine, FollowUpRunner $followUps): int
    {
        $lock = Cache::lock('helpdesk-workflows', 3500);
        if (! $lock->get()) {
            return self::SUCCESS;
        }
        try {
            Message::where('kind', 'outbound')->whereNotNull('rule_name')->whereNull('attempt_id')
                ->where('delivery', 'translation_pending')->where('updated_at', '<=', now()->subMinutes(15))
                ->chunkById(100, function ($messages): void {
                    foreach ($messages as $message) {
                        TranslateAutomatedReply::dispatch($message->id);
                    }
                });
            $followUps->run();
            Ticket::inInbox()->chunkById(100, function ($tickets) use ($engine) {
                foreach ($tickets as $ticket) {
                    $engine->run($ticket, 'time.elapsed');
                }
            });
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
