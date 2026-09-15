<?php

namespace App\Console\Commands;

use App\Models\Ticket;
use App\Models\WorkspaceSetting;
use App\Services\TicketCustomFields;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class FillShopifyDomains extends Command
{
    protected $signature = 'tickets:fill-shopify-domains
        {--dry-run : Count matching conversations without updating fields}
        {--create-field : Add the Shopify Domain definition if it does not exist}';

    protected $description = 'Fill empty Shopify Domain custom fields from domains found in ticket conversations';

    public function handle(TicketCustomFields $fields): int
    {
        if ($this->option('create-field') && ! $this->option('dry-run') && ! $this->ensureField()) {
            return self::FAILURE;
        }
        if ($fields->shopifyDomainKey() === null) {
            $this->error('Define a custom field named Shopify Domain before scanning conversations.');

            return self::FAILURE;
        }

        $updated = 0;
        $dryRun = (bool) $this->option('dry-run');
        Ticket::whereNull('merged_into_id')->chunkById(100, function (Collection $tickets) use ($fields, $dryRun, &$updated): void {
            foreach ($tickets as $ticket) {
                if ($fields->fillShopifyDomain($ticket, $fields->conversationSources($ticket), $dryRun)) {
                    $updated++;
                }
            }
        });
        $this->info(($dryRun ? 'Would fill ' : 'Filled ').$updated.' Shopify Domain field(s).');

        return self::SUCCESS;
    }

    private function ensureField(): bool
    {
        return DB::transaction(function (): bool {
            DB::table('workspace_settings')->insertOrIgnore(['key' => 'custom_fields', 'value' => json_encode(['revision' => 0, 'fields' => []]), 'created_at' => now(), 'updated_at' => now()]);
            $setting = WorkspaceSetting::whereKey('custom_fields')->lockForUpdate()->firstOrFail();
            $value = $setting->value;
            foreach ($value['fields'] as $field) {
                if (mb_strtolower(trim($field['name'])) === 'shopify domain') {
                    return true;
                }
            }
            if (count($value['fields']) >= 20) {
                $this->error('The workspace already has the maximum of 20 custom fields.');

                return false;
            }
            $value['fields'][] = ['key' => (string) Str::uuid(), 'name' => 'Shopify Domain'];
            $value['revision']++;
            $setting->update(['value' => $value]);
            $this->info('Added the Shopify Domain custom field.');

            return true;
        });
    }
}
