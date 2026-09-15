<?php

namespace App\Console\Commands;

use App\Services\MobilePush;
use Illuminate\Console\Command;

class ProcessMobilePush extends Command
{
    protected $signature = 'mobile:process-push';

    protected $description = 'Deliver committed mobile notifications, check receipts, and warn idle phones';

    public function handle(MobilePush $push): int
    {
        $push->process();

        return self::SUCCESS;
    }
}
