<?php

namespace Modules\Lead\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Modules\Lead\Models\Deal;
use Modules\Lead\Models\DealActivityLog;
use Modules\Lead\Models\DealStage;
use Modules\Lead\Models\DealTask;
use Modules\Lead\Models\Lead;
use Modules\Lead\Models\LeadActivityLog;
use Modules\Lead\Models\LeadStage;
use Modules\Lead\Models\LeadTask;
use Modules\Lead\Models\Workflow;
use Modules\Lead\Models\WorkflowRun;

/**
 * Zoho-style workflow rules: "when <trigger> happens to a lead / deal, if <conditions> match, do <actions>".
 *
 * The engine is called by LeadService / DealService after the change was made. It must never break the change it reacts to: every rule runs
 * in its own try/catch, a failure is written to the run history, and the actions write straight to the tables (not through the services), so
 * a rule can never start another rule.
 */
class WorkflowEngine
{
    public const OPERATORS = ['eq', 'neq', 'contains', 'gt', 'lt', 'empty', 'not_empty'];

    public const ACTIONS = ['create_task', 'send_email', 'change_stage', 'assign_user', 'add_note'];

    /** field => [label, kind]; kind decides which operators make sense (text, number, id, set). */
    public const FIELDS = [
        'lead' => ['name' => 'text', 'subject' => 'text', 'email' => 'text', 'phone' => 'text', 'stage' => 'id', 'pipeline' => 'id', 'source' => 'set', 'label' => 'set'],
        'deal' => ['name' => 'text', 'price' => 'number', 'status' => 'text', 'stage' => 'id', 'pipeline' => 'id', 'source' => 'set', 'label' => 'set'],
    ];

    private static bool $running = false;

    public function fire(string $trigger, Lead|Deal $record, ?int $actorId = null): void
    {
        if (self::$running) {
            return;
        }

        $record->refresh(); // a freshly created row has not loaded its column defaults (deal status...)
        $module = $record instanceof Lead ? 'lead' : 'deal';
        $rules = Workflow::where('created_by', $record->created_by)->where('module', $module)->where('trigger', $trigger)->where('is_active', true)->orderBy('id')->get();

        self::$running = true;
        try {
            foreach ($rules as $rule) {
                $this->run($rule, $record, $module, $actorId);
            }
        } finally {
            self::$running = false;
        }
    }

    private function run(Workflow $rule, Lead|Deal $record, string $module, ?int $actorId): void
    {
        try {
            if (!$this->matches($rule, $record, $module)) {
                return;
            }

            $done = [];
            foreach (array_slice($rule->actions ?? [], 0, 10) as $action) {
                $done[] = $this->act($action, $record, $module, $actorId);
            }

            $this->finish($rule, $record, $module, 'ok', implode('; ', array_filter($done)));
        } catch (\Throwable $e) {
            report($e);
            $this->finish($rule, $record, $module, 'failed', $e->getMessage());
        }
    }

    private function finish(Workflow $rule, Lead|Deal $record, string $module, string $status, string $message): void
    {
        WorkflowRun::create([
            'workflow_id' => $rule->id, 'record_type' => $module, 'record_id' => $record->id, 'status' => $status,
            'message' => mb_substr($message, 0, 500), 'created_by' => $rule->created_by, 'created_at' => now(),
        ]);
        $rule->forceFill(['last_run_at' => now(), 'run_count' => $rule->run_count + 1])->save();

        $text = $status === 'ok' ? __('Workflow ":name" ran', ['name' => $rule->name]) : __('Workflow ":name" failed: :error', ['name' => $rule->name, 'error' => $message]);
        $this->note($record, $module, $text);
    }

    // ───────────── conditions ─────────────

    private function matches(Workflow $rule, Lead|Deal $record, string $module): bool
    {
        $conditions = $rule->conditions ?? [];
        if (!$conditions) {
            return true;
        }

        $results = array_map(fn ($c) => $this->check($c, $record, $module), $conditions);

        return $rule->match === 'any' ? in_array(true, $results, true) : !in_array(false, $results, true);
    }

    /** @param array{field: string, operator: string, value?: mixed} $c */
    private function check(array $c, Lead|Deal $record, string $module): bool
    {
        $field = $c['field'] ?? '';
        $kind = self::FIELDS[$module][$field] ?? null;
        if (!$kind) {
            return false;
        }

        $actual = $this->value($record, $module, $field);
        $expected = $c['value'] ?? null;
        $op = $c['operator'] ?? 'eq';

        if ($kind === 'set') {
            $has = in_array((int) $expected, $actual, true);

            return match ($op) { 'neq' => !$has, 'empty' => $actual === [], 'not_empty' => $actual !== [], default => $has };
        }

        $blank = $actual === null || $actual === '';

        return match ($op) {
            'empty' => $blank,
            'not_empty' => !$blank,
            'neq' => !$this->same($actual, $expected, $kind),
            'contains' => !$blank && $expected !== null && $expected !== '' && mb_stripos((string) $actual, (string) $expected) !== false,
            'gt' => is_numeric($actual) && is_numeric($expected) && (float) $actual > (float) $expected,
            'lt' => is_numeric($actual) && is_numeric($expected) && (float) $actual < (float) $expected,
            default => $this->same($actual, $expected, $kind),
        };
    }

    private function same(mixed $actual, mixed $expected, string $kind): bool
    {
        return match ($kind) {
            'number', 'id' => is_numeric($actual) && is_numeric($expected) && (float) $actual === (float) $expected,
            default => mb_strtolower(trim((string) $actual)) === mb_strtolower(trim((string) $expected)),
        };
    }

    /** @return mixed scalar, or int[] for the "set" fields (sources, labels) */
    private function value(Lead|Deal $record, string $module, string $field): mixed
    {
        return match ($field) {
            'stage' => $record instanceof Lead ? $record->lead_stage_id : $record->deal_stage_id,
            'pipeline' => $record->pipeline_id,
            'source' => $record->sources()->pluck('sources.id')->map(fn ($v) => (int) $v)->all(),
            'label' => $record->labels()->pluck('labels.id')->map(fn ($v) => (int) $v)->all(),
            default => $record->getAttribute($field),
        };
    }

    // ───────────── actions ─────────────

    /** @param array<string, mixed> $a */
    private function act(array $a, Lead|Deal $record, string $module, ?int $actorId): string
    {
        $tenant = $record->created_by;

        switch ($a['type'] ?? '') {
            case 'create_task':
                $name = $this->fill((string) ($a['name'] ?? ''), $record, $module);
                $data = ['name' => mb_substr($name !== '' ? $name : __('Follow up'), 0, 190), 'due_date' => now()->addDays(max(0, min(365, (int) ($a['due_days'] ?? 1))))->toDateString(),
                    'priority' => in_array($a['priority'] ?? '', ['low', 'medium', 'high'], true) ? $a['priority'] : 'medium', 'status' => 'on_going', 'creator_id' => $actorId, 'created_by' => $tenant];
                $module === 'lead' ? LeadTask::create(['lead_id' => $record->id] + $data) : DealTask::create(['deal_id' => $record->id] + $data);

                return __('task ":name" created', ['name' => $data['name']]);

            case 'send_email':
                $to = $this->recipients($a, $record, $module);
                $subject = $this->fill((string) ($a['subject'] ?? ''), $record, $module);
                $body = $this->fill((string) ($a['body'] ?? ''), $record, $module);
                // after the surrounding transaction, so a rolled back change never sends a mail
                DB::afterCommit(function () use ($to, $subject, $body) {
                    foreach ($to as $address) {
                        try {
                            Mail::raw($body, fn ($m) => $m->to($address)->subject($subject));
                        } catch (\Throwable $e) {
                            report($e);
                        }
                    }
                });

                return __('e-mail to :count recipient(s)', ['count' => count($to)]);

            case 'change_stage':
                return $this->changeStage((int) ($a['stage_id'] ?? 0), $record, $module);

            case 'assign_user':
                $userId = (int) ($a['user_id'] ?? 0);
                $ok = app(LeadService::class)->assignableUserIds($tenant)->contains($userId);
                if (!$ok) {
                    throw new \RuntimeException(__('The user to assign no longer exists.'));
                }
                $record->users()->syncWithoutDetaching([$userId]);

                return __('assigned to :name', ['name' => (string) User::whereKey($userId)->value('name')]);

            case 'add_note':
                $text = $this->fill((string) ($a['text'] ?? ''), $record, $module);
                $this->note($record, $module, $text !== '' ? $text : __('Workflow note'));

                return __('note added');
        }

        throw new \RuntimeException(__('Unknown workflow action.'));
    }

    private function changeStage(int $stageId, Lead|Deal $record, string $module): string
    {
        $stageModel = $module === 'lead' ? LeadStage::class : DealStage::class;
        $stage = $stageModel::where('pipeline_id', $record->pipeline_id)->find($stageId);

        if (!$stage) {
            throw new \RuntimeException(__('The stage is not in the pipeline of this record.'));
        }
        if (($module === 'lead' && $record->is_converted) || ($module === 'deal' && $record->status !== 'active')) {
            throw new \RuntimeException(__('This record is closed and cannot be moved.'));
        }

        $column = $module === 'lead' ? 'lead_stage_id' : 'deal_stage_id';
        // last in the column
        $record->forceFill([$column => $stage->id, 'order' => (int) $record::where($column, $stage->id)->max('order') + 1])->save();

        return __('moved to :stage', ['stage' => $stage->name]);
    }

    /** @return string[] */
    private function recipients(array $a, Lead|Deal $record, string $module): array
    {
        $to = $a['to'] ?? 'assignees';
        $addresses = match ($to) {
            'contact' => $module === 'lead' ? [$record->email] : $record->clients()->pluck('users.email')->all(),
            'custom' => [$a['email'] ?? null],
            default => $record->users()->pluck('users.email')->all(),
        };

        return array_slice(array_values(array_unique(array_filter($addresses, fn ($e) => is_string($e) && filter_var($e, FILTER_VALIDATE_EMAIL)))), 0, 20);
    }

    /** {{name}} {{title}} {{stage}} {{price}}: unknown placeholders are removed. */
    private function fill(string $text, Lead|Deal $record, string $module): string
    {
        $stageModel = $module === 'lead' ? LeadStage::class : DealStage::class;
        $stageId = $module === 'lead' ? $record->lead_stage_id : $record->deal_stage_id;
        $vars = [
            'name' => (string) $record->name,
            'title' => (string) ($module === 'lead' ? $record->subject : $record->name),
            'stage' => (string) $stageModel::whereKey($stageId)->value('name'),
            'price' => $module === 'deal' ? number_format((float) $record->price, 2) : '',
        ];

        return trim((string) preg_replace_callback('/\{\{\s*(\w+)\s*\}\}/', fn ($m) => $vars[$m[1]] ?? '', $text));
    }

    private function note(Lead|Deal $record, string $module, string $text): void
    {
        $data = ['user_id' => null, 'type' => 'automation', 'remark' => mb_substr($text, 0, 500), 'created_by' => $record->created_by];
        $module === 'lead' ? LeadActivityLog::create(['lead_id' => $record->id] + $data) : DealActivityLog::create(['deal_id' => $record->id] + $data);
    }
}
