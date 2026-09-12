<?php

namespace Database\Seeders;

use App\Models\Activity;
use App\Models\Automation;
use App\Models\CannedReply;
use App\Models\Mailbox;
use App\Models\SavedView;
use App\Models\Team;
use App\Models\Ticket;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $support = Team::firstOrCreate(['name' => 'Support'], ['description' => 'Product questions and a helping hand.']);
        $billing = Team::firstOrCreate(['name' => 'Billing'], ['description' => 'Subscriptions, invoices, and payments.']);
        $sales = Team::firstOrCreate(['name' => 'Sales'], ['description' => 'Finding the right fit.']);
        $mailbox = Mailbox::firstOrCreate(['email' => 'hello@areviews.example'], ['name' => 'Customer support', 'team_id' => $support->id, 'color' => '#7450bb']);
        $billingBox = Mailbox::firstOrCreate(['email' => 'billing@areviews.example'], ['name' => 'Billing', 'team_id' => $billing->id, 'color' => '#b77908']);
        $salesBox = Mailbox::firstOrCreate(['email' => 'sales@areviews.example'], ['name' => 'Sales', 'team_id' => $sales->id, 'color' => '#24765e']);
        $reply = CannedReply::firstOrCreate(['shortcut' => '#hello'], ['title' => 'A warm welcome', 'category' => 'Greetings', 'body' => "Hi {{name}},\n\nThanks for reaching out! We’ve received your message and we’re happy to help. We’ll take a look and get back to you shortly.\n\nBest,\n{{agent}}"]);
        CannedReply::firstOrCreate(['shortcut' => '#details'], ['title' => 'A little more information', 'category' => 'Support', 'body' => "Hi {{name}},\n\nCould you share your store URL and a screenshot of what you’re seeing? That will help us find the right solution for you.\n\nBest,\n{{agent}}"]);
        CannedReply::firstOrCreate(['shortcut' => '#address'], ['title' => 'Address updated', 'category' => 'Orders', 'body' => "Hi {{name}},\n\nAll done! I’ve updated the shipping address on your order. You’ll receive a tracking email as soon as it’s on its way.\n\nBest,\n{{agent}}"]);
        CannedReply::firstOrCreate(['shortcut' => '#followup'], ['title' => 'Checking in', 'category' => 'Support', 'body' => "Hi {{name}},\n\nJust checking in to see if you still need a hand with this. Reply whenever you’re ready and we’ll pick up where we left off.\n\nBest,\n{{agent}}"]);
        foreach (['Orders', 'Billing', 'Technical', 'Onboarding'] as $name) {
            SavedView::firstOrCreate(['name' => $name], ['tag' => $name, 'filters' => []]);
        }
        Automation::firstOrCreate(['name' => 'Every conversation gets a welcome'], ['enabled' => false, 'trigger' => 'ticket.created', 'conditions' => ['source' => 'Email'], 'actions' => ['reply_id' => $reply->id]]);
        Automation::firstOrCreate(['name' => 'Send refunds to the right team'], ['enabled' => false, 'trigger' => 'ticket.created', 'conditions' => ['subject_contains' => 'refund'], 'actions' => ['team_id' => $billing->id, 'priority' => 'High', 'tag' => 'Refund']]);
        $samples = [
            [1042, 'Olivia Rhye', 'olivia@acme.example', 'Can I change my shipping address?', 'Open', 'High', ['Orders', 'Shipping'], "Hi there,\n\nI just placed order #AR-2048, but I noticed the shipping address is my old office. Could you update it before the order ships?\n\nThe new address is 24 King Street, London.\n\nThank you!\nOlivia", $mailbox, 'inbox'],
            [1041, 'Phoenix Baker', 'phoenix@layers.example', 'The review widget isn’t showing on mobile', 'Open', 'Urgent', ['Technical'], "Hello,\n\nOur review widget looks great on desktop, but it disappears when I open the store on my phone. Could you help us take a look?", $mailbox, 'inbox'],
            [1040, 'Lana Steiner', 'lana@sisyphus.example', 'Thank you for the quick help!', 'Closed', 'Normal', ['Feedback'], 'Everything is working perfectly now. Thank you for the quick help — really appreciate it!', $mailbox, 'inbox'],
            [1039, 'Demi Wilkinson', 'demi@catalog.example', 'Question about the annual plan', 'Pending', 'Normal', ['Billing'], "Hi team,\n\nWe’re thinking of moving to an annual subscription. Could you tell me how the remaining balance on our monthly plan would be handled?", $billingBox, 'inbox'],
            [1038, 'Drew Cano', 'drew@circooles.example', 'A copy of our September invoice', 'Open', 'Normal', ['Billing'], 'Could you send us a copy of the September invoice for our accounts team?', $billingBox, 'inbox'],
            [1037, 'Natali Craig', 'natali@feather.example', 'Importing reviews from our old store', 'Pending', 'Normal', ['Import'], 'We’re moving to a new store. Can we bring our old reviews with us?', $mailbox, 'inbox'],
            [1036, 'Orlando Diggs', 'orlando@orbit.example', 'New store setup request', 'On hold', 'Normal', ['Onboarding', 'Webhook'], "Store: Orbit Supply\nRequest: Help configuring review collection for our new storefront.", $salesBox, 'inbox'],
            [1035, 'Andi Lane', 'andi@untitled.example', 'Quick question about review requests', 'Open', 'Low', ['Automation'], 'Can we wait 7 days after delivery before sending a review request? We want customers to have time to try the product first.', $mailbox, 'inbox'],
            [1034, 'Kate Morrison', 'kate@hourglass.example', 'Customizing the star colors', 'Solved', 'Low', ['Technical'], 'The custom colors look exactly right now. Thanks for walking me through it!', $mailbox, 'inbox'],
            [1033, 'Alex Morgan', 'alex@northstar.example', 'Getting started with our new store', 'Solved', 'Normal', ['Onboarding'], 'Our new store is all set up. Thanks again for helping with the import.', $salesBox, 'archive'],
            [1032, null, 'offers@promotions.example', 'Special offer for your business', 'Open', 'Low', [], 'A sample spam conversation.', $mailbox, 'spam'],
            [1031, null, 'duplicate@example.com', 'Duplicate test request', 'Closed', 'Low', [], 'This sample ticket was moved to Trash and can be restored.', $mailbox, 'trash'],
        ];
        foreach ($samples as $index => [$id, $name, $email, $subject, $status, $priority, $tags, $body, $box, $folder]) {
            if (Ticket::find($id)) {
                continue;
            }
            $date = now()->subMinutes(2 + $index * 27);
            $ticket = new Ticket(['requester_name' => $name, 'requester_email' => $email, 'subject' => $subject, 'status' => $status, 'priority' => $priority,
                'tags' => $tags, 'mailbox_id' => $box->id, 'team_id' => $box->team_id, 'source' => $id === 1036 ? 'Webhook' : 'Email', 'folder' => $folder,
                'unread' => in_array($id, [1042, 1041, 1039]), 'last_activity_at' => $date, 'resolved_at' => in_array($status, ['Solved', 'Closed']) ? $date : null]);
            $ticket->id = $id;
            $ticket->created_at = $date->copy()->subHours(2);
            $ticket->save();
            $ticket->messages()->create(['body' => $body, 'kind' => 'inbound', 'author_name' => $name, 'author_email' => $email, 'created_at' => $date->copy()->subMinutes(20)]);
            if ($id === 1042) {
                $ticket->messages()->create(['body' => "Hi Olivia,\n\nThanks for reaching out! We’ve received your request and our team will take a look shortly.", 'kind' => 'outbound', 'author_name' => 'Automated message', 'author_email' => $box->email, 'rule_name' => 'Every conversation gets a welcome · sample', 'delivery' => 'saved', 'created_at' => $date->copy()->subMinutes(18)]);
                $ticket->messages()->create(['body' => 'I’ve checked the order. It hasn’t been dispatched yet, so we can still update the address.', 'kind' => 'note', 'author_name' => 'Ahmad', 'created_at' => $date->copy()->subMinutes(8)]);
                $ticket->messages()->create(['body' => 'One more thing — the postcode is W6 9QH. Thank you so much for your help!', 'kind' => 'inbound', 'author_name' => $name, 'author_email' => $email, 'created_at' => $date]);
                $ticket->update(['custom_fields' => ['Order number' => 'AR-2048', 'Store URL' => 'acme.example']]);
            }
            if ($id === 1038) {
                $ticket->messages()->create(['body' => "Hi Drew,\n\nYour September invoice is ready in Settings → Billing → Invoices.", 'kind' => 'outbound', 'author_name' => 'Ahmad', 'author_email' => $box->email, 'delivery' => 'failed', 'delivery_error' => 'Sample delivery failure: mailbox not found.', 'created_at' => $date]);
            }
            Activity::create(['ticket_id' => $id, 'description' => 'Sample conversation #'.$id.' added', 'created_at' => $date]);
        }
    }
}
