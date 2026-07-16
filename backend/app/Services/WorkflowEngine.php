<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\Workflow;
use App\Notifications\WorkflowNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

/**
 * Evaluates and runs user-defined workflows (project-stack.md §11).
 *
 * A workflow fires on a trigger (entity + event). Its conditions (IF, AND-ed)
 * are tested against the entity's attributes; matching runs the THEN actions,
 * otherwise the ELSE actions. Actions cover approval (set status), notification,
 * and setting a field.
 */
class WorkflowEngine
{
    /**
     * Trigger catalogue: key => [label, fields available to conditions/actions].
     * `model` is the Eloquent class whose `created` event fires the trigger.
     */
    public const TRIGGERS = [
        'expense.created' => [
            'label' => 'Expense created',
            'model' => \App\Models\Expense::class,
            'fields' => ['amount', 'status', 'payment_method', 'payee'],
            'link' => '/expenses',
        ],
        'sales_order.created' => [
            'label' => 'Sales Order created',
            'model' => \App\Models\SalesOrder::class,
            'fields' => ['total', 'status'],
            'link' => '/sales-orders',
        ],
        'purchase_order.created' => [
            'label' => 'Purchase Order created',
            'model' => \App\Models\PurchaseOrder::class,
            'fields' => ['total', 'status'],
            'link' => '/purchase-orders',
        ],
    ];

    public const OPERATORS = ['=', '!=', '>', '<', '>=', '<=', 'contains'];

    /**
     * Handle a fired trigger against a model: run every active workflow for it.
     */
    public function handle(string $trigger, Model $model): void
    {
        if (! isset(self::TRIGGERS[$trigger])) {
            return;
        }

        $workflows = Workflow::where('trigger', $trigger)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        foreach ($workflows as $workflow) {
            $this->run($workflow, $model);
        }
    }

    /** Evaluate one workflow and execute the matching action branch. */
    public function run(Workflow $workflow, Model $model): void
    {
        $data = $model->getAttributes();
        $matched = $this->evaluate($workflow->conditions ?? [], $data);
        $actions = $matched ? ($workflow->then_actions ?? []) : ($workflow->else_actions ?? []);

        $this->runActions($actions, $workflow, $model);
    }

    /**
     * True when every condition passes (AND). No conditions ⇒ always true.
     *
     * @param  array<int,array{field:string,operator:string,value:mixed}>  $conditions
     * @param  array<string,mixed>  $data
     */
    public function evaluate(array $conditions, array $data): bool
    {
        foreach ($conditions as $c) {
            $left = $data[$c['field']] ?? null;
            if (! $this->compare($left, $c['operator'] ?? '=', $c['value'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function compare(mixed $left, string $op, mixed $right): bool
    {
        // Numeric comparison when both sides look numeric.
        $numeric = is_numeric($left) && is_numeric($right);
        $l = $numeric ? (float) $left : $left;
        $r = $numeric ? (float) $right : $right;

        return match ($op) {
            '=' => $l == $r,
            '!=' => $l != $r,
            '>' => $l > $r,
            '<' => $l < $r,
            '>=' => $l >= $r,
            '<=' => $l <= $r,
            'contains' => is_string($left) && is_string($right) && str_contains(strtolower($left), strtolower($right)),
            default => false,
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $actions
     * @return array<int,string>  human-readable log of what ran (used by test-run)
     */
    public function runActions(array $actions, Workflow $workflow, Model $model): array
    {
        $log = [];

        foreach ($actions as $action) {
            $type = $action['type'] ?? null;

            $log[] = match ($type) {
                'set_status' => $this->applySetStatus($model, $action),
                'set_field' => $this->applySetField($model, $action),
                'notify' => $this->applyNotify($workflow, $model, $action),
                default => "Skipped unknown action '{$type}'.",
            };
        }

        return $log;
    }

    private function applySetStatus(Model $model, array $action): string
    {
        $value = $action['value'] ?? null;
        if ($value === null || ! in_array('status', array_keys($model->getAttributes()), true)) {
            return 'set_status skipped (no status column / no value).';
        }
        // saveQuietly avoids re-firing observers (prevents loops).
        $model->status = $value;
        $model->saveQuietly();

        return "Set status → {$value}.";
    }

    private function applySetField(Model $model, array $action): string
    {
        $field = $action['field'] ?? null;
        if (! $field || ! array_key_exists($field, $model->getAttributes())) {
            return 'set_field skipped (unknown field).';
        }
        $model->setAttribute($field, $action['value'] ?? null);
        $model->saveQuietly();

        return "Set {$field} → " . ($action['value'] ?? 'null') . '.';
    }

    private function applyNotify(Workflow $workflow, Model $model, array $action): string
    {
        $recipients = $this->resolveRecipients($action['to'] ?? 'creator', $model);
        if ($recipients->isEmpty()) {
            return 'notify skipped (no recipients).';
        }

        $link = self::TRIGGERS[$workflow->trigger]['link'] ?? null;
        $title = $action['title'] ?? $workflow->name;
        $body = $action['message'] ?? "Workflow '{$workflow->name}' triggered.";

        Notification::send($recipients, new WorkflowNotification($title, $body, $link));

        return "Notified {$recipients->count()} user(s).";
    }

    /**
     * Resolve a recipient spec to a collection of Users:
     *   creator | role:{name} | user:{id}
     *
     * @return \Illuminate\Support\Collection<int,User>
     */
    private function resolveRecipients(string $spec, Model $model): \Illuminate\Support\Collection
    {
        if ($spec === 'creator') {
            $id = $model->getAttribute('created_by');

            return $id ? User::where('id', $id)->get() : collect();
        }

        if (str_starts_with($spec, 'user:')) {
            return User::where('id', (int) substr($spec, 5))->get();
        }

        if (str_starts_with($spec, 'role:')) {
            return User::role(substr($spec, 5))->get();
        }

        return collect();
    }
}
