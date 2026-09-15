<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Automation;
use App\Models\Ticket;
use App\Services\WorkflowActions;
use App\Services\WorkflowConditions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class WorkflowController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'rules' => Automation::all(),
            'macros' => DB::table('macros')->orderBy('name')->get()->map(fn ($macro) => $this->macro($macro)),
            'runs' => DB::table('automation_runs')->join('automations', 'automations.id', '=', 'automation_runs.automation_id')->join('tickets', 'tickets.id', '=', 'automation_runs.ticket_id')->select('automation_runs.*', 'automations.name', 'tickets.subject')->orderByDesc('automation_runs.id')->limit(50)->get(),
            'follow_ups' => DB::table('follow_ups')->join('tickets', 'tickets.id', '=', 'follow_ups.ticket_id')->select('follow_ups.*', 'tickets.subject')->orderByDesc('follow_ups.id')->limit(50)->get(),
        ]);
    }

    private function macro(object $macro): array
    {
        return [...(array) $macro, 'enabled' => (bool) $macro->enabled, 'actions' => json_decode($macro->actions, true)];
    }

    public function save(Request $request, string $kind = 'rules', ?int $id = null): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_unless(in_array($kind, ['rules', 'macros']), 404);
        $data = $request->validate([
            'name' => 'required|string|max:120', 'enabled' => 'required|boolean',
            'actions' => 'required|array|min:1|max:20',
            ...($kind === 'rules' ? [
                'description' => 'nullable|string|max:2000',
                'trigger' => 'required|in:ticket.created,ticket.updated,message.received,message.sent,time.elapsed',
                'conditions' => 'present|array', 'repeat_mode' => 'sometimes|in:once,event,interval',
                'interval_minutes' => 'sometimes|integer|between:1,525600', 'max_runs' => 'sometimes|integer|between:1,1000',
            ] : []),
        ]);
        $data['actions'] = $this->validateActions($data['actions']);
        if ($kind === 'rules') {
            $data['conditions'] = $this->validateConditions($data['conditions']);
            abort_if(($data['trigger'] === 'time.elapsed') && ($data['repeat_mode'] ?? 'once') === 'event', 422, 'Time rules must run once or at an interval.');
            $rule = $id ? Automation::findOrFail($id) : new Automation;
            $rule->fill($data)->save();
            $result = $rule->toArray();
        } else {
            if ($id) {
                abort_unless(DB::table('macros')->where('id', $id)->exists(), 404);
                DB::table('macros')->where('id', $id)->update([...$data, 'actions' => json_encode($data['actions']), 'updated_at' => now()]);
            } else {
                $id = DB::table('macros')->insertGetId([...$data, 'actions' => json_encode($data['actions']), 'created_at' => now(), 'updated_at' => now()]);
            }
            $result = $this->macro(DB::table('macros')->find($id));
        }
        Activity::create(['user_id' => $request->user()->id, 'description' => 'Saved '.$kind.': '.$data['name']]);

        return response()->json($result);
    }

    public function validateActions(array $actions): array
    {
        $actions = WorkflowActions::normalize($actions);
        Validator::make(['actions' => $actions], [
            'actions' => 'required|array|min:1|max:20', 'actions.*' => 'required|array:type,value,body',
            'actions.*.type' => ['required', Rule::in(WorkflowActions::TYPES)],
        ])->validate();
        foreach ($actions as $action) {
            $rules = match ($action['type']) {
                'status' => ['required', Rule::in(Ticket::STATUSES)],
                'priority' => ['required', Rule::in(Ticket::PRIORITIES)],
                'folder' => 'required|in:inbox,archive,spam,trash',
                'assignee_id' => 'nullable|integer|exists:users,id', 'team_id' => 'nullable|integer|exists:teams,id',
                'add_tag', 'remove_tag' => 'required|string|max:60',
                'reply_id' => 'required|integer|exists:canned_replies,id',
                'note', 'send_message', 'send_follow_up' => 'required|string|max:20000',
                'follow_up' => 'required|integer|between:1,525600',
            };
            Validator::make($action, ['value' => $rules, ...($action['type'] === 'follow_up' ? ['body' => 'required|string|max:20000'] : [])])->validate();
        }

        return $actions;
    }

    private function validateConditions(array $conditions): array
    {
        if (! array_key_exists('all', $conditions) && ! array_key_exists('any', $conditions)) {
            $converted = [];
            foreach ($conditions as $field => $value) {
                if ($value !== '' && $value !== null) {
                    $converted[] = ['field' => $field === 'subject_contains' ? 'subject' : $field, 'operator' => $field === 'subject_contains' ? 'contains' : 'eq', 'value' => (string) $value];
                }
            }
            $conditions = ['all' => $converted, 'any' => []];
        }
        Validator::make(['conditions' => $conditions], [
            'conditions' => 'array:all,any', 'conditions.all' => 'sometimes|array|max:20', 'conditions.any' => 'sometimes|array|max:20',
            'conditions.*.*' => 'array:field,operator,value,events,scope,case_sensitive',
            'conditions.*.*.events' => 'sometimes|array|min:1|max:4',
            'conditions.*.*.events.*' => ['required', Rule::in(WorkflowConditions::EVENTS)],
            'conditions.*.*.scope' => 'sometimes|in:anyone,rule',
            'conditions.*.*.case_sensitive' => 'sometimes|boolean',
            'conditions.*.*.field' => ['required', Rule::in(WorkflowConditions::FIELDS)],
            'conditions.*.*.operator' => ['required', Rule::in(WorkflowConditions::OPERATORS)],
            'conditions.*.*.value' => ['nullable', function (string $attribute, mixed $value, \Closure $fail) {
                if ((! is_string($value) && ! is_numeric($value)) || mb_strlen((string) $value) > 255) {
                    $fail('Condition values must be text or a number, up to 255 characters.');
                }
            }],
        ])->validate();
        foreach ([...($conditions['all'] ?? []), ...($conditions['any'] ?? [])] as $condition) {
            abort_if($condition['field'] === 'since_events_minutes' && empty($condition['events']), 422, 'Choose at least one event for the timer.');
            abort_if(isset($condition['events']) && count($condition['events']) !== count(array_unique($condition['events'])), 422, 'Choose each timer event only once.');
            if (str_ends_with($condition['field'], '_count') || in_array($condition['field'], ['since_status_minutes', 'since_events_minutes'])) {
                abort_unless(in_array($condition['operator'], ['eq', 'neq', 'gte', 'lte', 'empty', 'not_empty']), 422, 'Choose a numeric comparison for counts and timers.');
                if (! in_array($condition['operator'], ['empty', 'not_empty'])) {
                    abort_unless(is_numeric($condition['value'] ?? null) && $condition['value'] >= 0, 422, 'Counts and timers require a non-negative number.');
                    if (str_ends_with($condition['field'], '_count')) {
                        abort_unless(filter_var($condition['value'], FILTER_VALIDATE_INT) !== false, 422, 'Event counts must be whole numbers.');
                    }
                }
            }
            abort_if(in_array($condition['operator'], ['contains_phrase', 'not_contains_phrase']) && ! in_array($condition['field'], ['subject', 'body']), 422, 'Phrase matching is available for the subject and customer message.');
            abort_if(($condition['case_sensitive'] ?? false) && ! in_array($condition['field'], ['subject', 'body']), 422, 'Case-sensitive matching is available for the subject and customer message.');
            if (in_array($condition['operator'], ['gte', 'lte'])) {
                abort_unless(is_numeric($condition['value'] ?? null), 422, 'Numeric comparisons require a number.');
            }
            if (! in_array($condition['operator'], ['empty', 'not_empty'])) {
                abort_if(! isset($condition['value']) || $condition['value'] === '', 422, 'Enter a value for each condition.');
            }
        }

        return $conditions;
    }

    public function destroy(Request $request, string $kind, int $id): JsonResponse
    {
        abort_unless($request->user()->role === 'admin', 403);
        abort_unless(in_array($kind, ['rules', 'macros']), 404);
        DB::table($kind === 'rules' ? 'automations' : 'macros')->where('id', $id)->delete();

        return response()->json(['deleted' => true]);
    }
}
