<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Workflow;
use App\Services\WorkflowEngine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * CRUD + metadata for the Workflow Builder (project-stack.md §11).
 */
class WorkflowController extends Controller
{
    /** List the tenant's workflows. */
    public function index(Request $request): JsonResponse
    {
        $query = Workflow::query()->orderBy('sort_order')->orderByDesc('id');

        if ($request->filled('trigger')) {
            $query->where('trigger', $request->query('trigger'));
        }
        if ($request->filled('search')) {
            $query->where('name', 'like', '%' . $request->query('search') . '%');
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * Builder metadata: available triggers (+ their fields), comparison
     * operators, action types and recipient options. Powers the UI.
     */
    public function meta(): JsonResponse
    {
        return response()->json([
            'triggers' => collect(WorkflowEngine::TRIGGERS)
                ->map(fn ($meta, $key) => [
                    'key' => $key,
                    'label' => $meta['label'],
                    'fields' => $meta['fields'],
                ])
                ->values(),
            'operators' => WorkflowEngine::OPERATORS,
            'action_types' => [
                ['key' => 'set_status', 'label' => 'Set approval status'],
                ['key' => 'notify', 'label' => 'Send notification'],
                ['key' => 'set_field', 'label' => 'Set a field value'],
            ],
            'recipients' => [
                ['key' => 'creator', 'label' => 'Record creator'],
                ['key' => 'role:admin', 'label' => 'All admins'],
                ['key' => 'role:manager', 'label' => 'All managers'],
            ],
        ]);
    }

    public function show(Workflow $workflow): JsonResponse
    {
        return response()->json(['data' => $workflow]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request);
        $data['created_by'] = $request->user()->id;

        $workflow = Workflow::create($data);

        return response()->json(['data' => $workflow], 201);
    }

    public function update(Request $request, Workflow $workflow): JsonResponse
    {
        $workflow->update($this->validated($request));

        return response()->json(['data' => $workflow]);
    }

    public function destroy(Workflow $workflow): JsonResponse
    {
        $workflow->delete();

        return response()->json(['message' => 'Workflow deleted.']);
    }

    /**
     * Dry-run: evaluate a workflow's conditions against sample data and report
     * which branch would run and what its actions would do — without executing.
     */
    public function test(Request $request): JsonResponse
    {
        $data = $request->validate([
            'conditions' => ['array'],
            'then_actions' => ['array'],
            'else_actions' => ['array'],
            'sample' => ['required', 'array'],
        ]);

        $engine = app(WorkflowEngine::class);
        $matched = $engine->evaluate($data['conditions'] ?? [], $data['sample']);
        $actions = $matched ? ($data['then_actions'] ?? []) : ($data['else_actions'] ?? []);

        return response()->json([
            'matched' => $matched,
            'branch' => $matched ? 'THEN' : 'ELSE',
            'would_run' => array_map(fn ($a) => $this->describeAction($a), $actions),
        ]);
    }

    /** @return array<string,mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
            'trigger' => ['required', Rule::in(array_keys(WorkflowEngine::TRIGGERS))],
            'conditions' => ['array'],
            'conditions.*.field' => ['required_with:conditions', 'string'],
            'conditions.*.operator' => ['required_with:conditions', Rule::in(WorkflowEngine::OPERATORS)],
            'conditions.*.value' => ['nullable'],
            'then_actions' => ['array'],
            'then_actions.*.type' => ['required_with:then_actions', Rule::in(['set_status', 'notify', 'set_field'])],
            'else_actions' => ['array'],
            'else_actions.*.type' => ['required_with:else_actions', Rule::in(['set_status', 'notify', 'set_field'])],
            'is_active' => ['boolean'],
            'sort_order' => ['integer'],
        ]);
    }

    private function describeAction(array $action): string
    {
        return match ($action['type'] ?? null) {
            'set_status' => 'Set status → ' . ($action['value'] ?? '?'),
            'set_field' => 'Set ' . ($action['field'] ?? '?') . ' → ' . ($action['value'] ?? '?'),
            'notify' => 'Notify ' . ($action['to'] ?? 'creator') . ': ' . ($action['message'] ?? ''),
            default => 'Unknown action',
        };
    }
}
