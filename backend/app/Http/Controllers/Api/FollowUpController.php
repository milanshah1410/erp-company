<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\FollowUpRequest;
use App\Http\Resources\FollowUpResource;
use App\Models\FollowUp;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class FollowUpController extends Controller
{
    public function index(Request $request)
    {
        $query = FollowUp::query()->with(['assignee:id,name', 'followupable']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('assigned_to')) {
            $query->where('assigned_to', $request->query('assigned_to'));
        }

        // Filter to one related party, e.g. related_type=lead&related_id=5
        if ($request->filled('related_type') && $request->filled('related_id')) {
            $class = FollowUpRequest::RELATED_TYPES[$request->query('related_type')] ?? null;
            if ($class) {
                $query->where('followupable_type', $class)
                    ->where('followupable_id', $request->query('related_id'));
            }
        }

        // Agenda scopes for the reminder screens.
        $scope = $request->query('scope');
        if ($scope === 'overdue') {
            $query->overdue();
        } elseif ($scope === 'today') {
            $query->pending()->whereBetween('due_at', [now()->startOfDay(), now()->endOfDay()]);
        } elseif ($scope === 'upcoming') {
            $query->pending()->where('due_at', '>', now());
        }

        $query->orderBy('due_at', $request->query('direction', 'asc') === 'desc' ? 'desc' : 'asc');

        $perPage = min((int) $request->query('per_page', 15), 100);

        return FollowUpResource::collection($query->paginate($perPage));
    }

    public function store(FollowUpRequest $request)
    {
        $data = $request->validated();

        $relatedClass = $request->relatedModelClass();
        // Ensure the related record exists within this tenant before linking.
        $related = $relatedClass::query()->find($data['related_id']);
        if (! $related) {
            throw ValidationException::withMessages([
                'related_id' => 'The selected related record does not exist.',
            ]);
        }

        $followUp = FollowUp::create([
            'followupable_type' => $relatedClass,
            'followupable_id' => $related->id,
            'type' => $data['type'] ?? 'call',
            'due_at' => $data['due_at'],
            'title' => $data['title'] ?? null,
            'notes' => $data['notes'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'status' => 'pending',
            'created_by' => $request->user()?->id,
        ]);

        return (new FollowUpResource($followUp->load('assignee', 'followupable')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(FollowUp $followUp)
    {
        return new FollowUpResource($followUp->load('assignee', 'followupable'));
    }

    public function update(FollowUpRequest $request, FollowUp $followUp)
    {
        // related_* are create-only; validated() omits them on update.
        $followUp->update($request->validated());

        return new FollowUpResource($followUp->load('assignee', 'followupable'));
    }

    public function destroy(FollowUp $followUp)
    {
        $followUp->delete();

        return response()->json(['message' => 'Follow-up deleted.']);
    }

    /** Mark a follow-up done (or cancelled) with an outcome note. */
    public function complete(Request $request, FollowUp $followUp)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'in:done,cancelled'],
            'outcome' => ['nullable', 'string'],
        ]);

        if ($followUp->status !== 'pending') {
            return response()->json(['message' => 'This follow-up is already closed.'], 422);
        }

        $followUp->update([
            'status' => $validated['status'] ?? 'done',
            'outcome' => $validated['outcome'] ?? $followUp->outcome,
            'completed_at' => now(),
        ]);

        return new FollowUpResource($followUp->load('assignee', 'followupable'));
    }
}
