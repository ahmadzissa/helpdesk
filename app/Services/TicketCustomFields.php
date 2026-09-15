<?php

namespace App\Services;

use App\Models\Message;
use App\Models\Ticket;
use App\Models\WorkspaceSetting;
use Generator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketCustomFields
{
    /** @return array{revision:int, fields:array<int, array{key:string, name:string}>} */
    public function settings(): array
    {
        return WorkspaceSetting::find('custom_fields')?->value ?? ['revision' => 0, 'fields' => []];
    }

    public function shopifyDomainKey(): ?string
    {
        foreach ($this->settings()['fields'] as $field) {
            if (mb_strtolower(trim($field['name'])) === 'shopify domain') {
                return $field['key'];
            }
        }

        return null;
    }

    /** @param iterable<string> $sources */
    public function detectShopifyDomain(iterable $sources): ?string
    {
        $domains = $this->detectShopifyDomains($sources);

        return count($domains) === 1 ? $domains[0] : null;
    }

    /**
     * @param  iterable<string>  $sources
     * @return list<string>
     */
    public function detectShopifyDomains(iterable $sources): array
    {
        $domains = [];
        foreach ($sources as $source) {
            $text = html_entity_decode($source, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            preg_match_all('/(?<![\p{L}\p{N}_.%\-])([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.myshopify\.com)(?![\p{L}\p{N}_%\-]|\.[\p{L}\p{N}_\-])/iu', $text, $matches);
            foreach ($matches[1] as $domain) {
                $domains[mb_strtolower($domain)] = true;
            }
        }

        return array_keys($domains);
    }

    /** @return Generator<int, string> */
    private function requesterShopifyInputs(Ticket $ticket, string $key): Generator
    {
        $email = mb_strtolower(trim($ticket->requester_email));
        if ($email === '') {
            return;
        }
        $tickets = Ticket::where('id', '!=', $ticket->id)->whereRaw('LOWER(requester_email) = ?', [$email])
            ->select(['id', 'custom_fields'])->lazyById(100);
        foreach ($tickets as $other) {
            yield $other->custom_fields[$key] ?? '';
        }
    }

    /** @return Generator<int, string> */
    public function conversationSources(Ticket $ticket): Generator
    {
        yield $ticket->subject;
        $ticketIds = [$ticket->id, ...$ticket->mergedTickets()->pluck('id')->all()];
        foreach (Message::whereIn('ticket_id', $ticketIds)->select(['id', 'body', 'email_html'])->lazyById(100) as $message) {
            yield $message->body;
            yield $message->email_html ?? '';
        }
    }

    /** @param iterable<string> $sources */
    public function fillShopifyDomain(Ticket $ticket, iterable $sources, bool $dryRun = false): bool
    {
        $key = $this->shopifyDomainKey();
        if ($key === null || $ticket->merged_into_id || trim($ticket->custom_fields[$key] ?? '') !== '') {
            return false;
        }
        $domains = $this->detectShopifyDomains($sources);
        if (count($domains) > 1) {
            return false;
        }
        if ($domains === []) {
            $domains = $this->detectShopifyDomains($this->requesterShopifyInputs($ticket, $key));
        }
        $domain = implode(', ', $domains);
        if ($domain === '' || mb_strlen($domain) > 500) {
            return false;
        }
        if ($dryRun) {
            return true;
        }

        return DB::transaction(function () use ($ticket, $key, $domain): bool {
            $current = Ticket::whereKey($ticket->id)->lockForUpdate()->first();
            if (! $current || $current->merged_into_id || trim($current->custom_fields[$key] ?? '') !== '') {
                return false;
            }
            Ticket::whereKey($current->id)->update(['custom_fields' => array_replace($current->custom_fields ?? [], [$key => $domain])]);

            return true;
        });
    }

    public function validateValues(array $values): void
    {
        $keys = array_column($this->settings()['fields'], 'key');
        if (array_diff(array_keys($values), $keys) !== []) {
            throw ValidationException::withMessages(['custom_fields' => 'Choose fields defined in Settings → Custom fields. Reload this ticket if a field was removed.']);
        }
    }
}
