<?php

namespace Tests\Feature;

use App\Models\Automation;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AutomationEngine;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AutomationUsageCountTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_rule_reports_its_full_usage_independently_of_recent_history(): void
    {
        $user = User::factory()->create();
        $used = Automation::factory()->create(['repeat_mode' => 'event']);
        $unused = Automation::factory()->create(['enabled' => false]);
        $once = Automation::factory()->create();
        $ticket = Ticket::factory()->create();
        $engine = app(AutomationEngine::class);
        for ($run = 0; $run < 51; $run++) {
            $engine->run($ticket, 'ticket.created');
        }
        $used->update(['enabled' => false]);

        $response = $this->actingAs($user)->getJson('/api/v1/workflows');

        $response->assertOk()->assertJsonCount(50, 'runs')->assertJsonCount(3, 'rules');
        $rules = collect($response->json('rules'))->keyBy('id');
        $this->assertSame(51, $rules[$used->id]['usage_count']);
        $this->assertSame(0, $rules[$unused->id]['usage_count']);
        $this->assertSame(1, $rules[$once->id]['usage_count']);
    }

    public function test_failed_actions_do_not_increase_usage(): void
    {
        $user = User::factory()->create();
        $rule = Automation::factory()->create(['actions' => [['type' => 'priority', 'value' => 'High'], ['type' => 'note', 'value' => 'A note']]]);
        $ticket = Ticket::factory()->create(['priority' => 'Normal']);
        DB::statement("CREATE TRIGGER fail_automation_note BEFORE INSERT ON messages BEGIN SELECT RAISE(ABORT, 'Action failed'); END");
        try {
            app(AutomationEngine::class)->run($ticket, 'ticket.created');
            $this->fail('The note action should fail.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('Action failed', $exception->getMessage());
        } finally {
            DB::statement('DROP TRIGGER fail_automation_note');
        }

        $response = $this->actingAs($user)->getJson('/api/v1/workflows');

        $response->assertOk()->assertJsonPath('rules.0.id', $rule->id)->assertJsonPath('rules.0.usage_count', 0);
        $this->assertDatabaseHas('tickets', ['id' => $ticket->id, 'priority' => 'Normal']);
        $this->assertDatabaseCount('automation_runs', 0);
        $this->assertDatabaseCount('messages', 0);
    }
}
