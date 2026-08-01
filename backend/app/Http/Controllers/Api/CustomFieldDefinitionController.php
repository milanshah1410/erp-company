<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomFieldDefinitionRequest;
use App\Http\Resources\CustomFieldDefinitionResource;
use App\Models\CustomFieldDefinition;
use App\Services\CustomFieldService;
use App\Support\CustomFieldEntities;
use App\Support\Modules;
use Illuminate\Http\Request;

/**
 * The field builder: an admin defines what extra data this shop keeps, and
 * every form, list and validator picks it up without a deployment.
 *
 * Two of these endpoints are not CRUD. {@see entities()} is the picker the
 * builder opens with. {@see schema()} is what the form renderer consumes on
 * every product/customer screen — it is deliberately reachable by any signed-in
 * user, because it is form metadata: a salesman who can create a product has to
 * be able to see the fields they are required to fill in, without also holding
 * permission to redefine them.
 */
class CustomFieldDefinitionController extends Controller
{
    public function __construct(private readonly CustomFieldService $fields) {}

    public function index(Request $request)
    {
        $query = CustomFieldDefinition::query();

        if ($entity = $request->query('entity')) {
            $query->where('entity', $entity);
        }
        if ($request->filled('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('label', 'like', "%{$search}%")
                    ->orWhere('key', 'like', "%{$search}%");
            });
        }

        $query->orderBy('entity')->orderBy('sort_order')->orderBy('id');

        $perPage = min((int) $request->query('per_page', 50), 200);

        return CustomFieldDefinitionResource::collection($query->paginate($perPage));
    }

    public function store(CustomFieldDefinitionRequest $request)
    {
        $definition = CustomFieldDefinition::create($request->validated() + [
            'created_by' => $request->user()?->id,
        ]);

        return (new CustomFieldDefinitionResource($definition))
            ->response()->setStatusCode(201);
    }

    public function show(CustomFieldDefinition $customFieldDefinition)
    {
        return new CustomFieldDefinitionResource($customFieldDefinition);
    }

    public function update(CustomFieldDefinitionRequest $request, CustomFieldDefinition $customFieldDefinition)
    {
        // Identity is fixed after creation — see the request for why. Dropped
        // rather than rejected so a client that echoes the whole record back
        // (which every form does) still succeeds.
        $data = collect($request->validated())->except(['entity', 'key'])->all();

        $customFieldDefinition->update($data);

        return new CustomFieldDefinitionResource($customFieldDefinition);
    }

    /**
     * Retire a field.
     *
     * Values already stored under its key are left in place. They stop being
     * read, exported or validated the moment the definition goes, so deleting
     * is reversible: recreating the field with the same key brings the old data
     * back rather than showing a column of blanks. Switching `is_active` off
     * does the same thing without the delete.
     */
    public function destroy(CustomFieldDefinition $customFieldDefinition)
    {
        $customFieldDefinition->delete();

        return response()->json([
            'message' => 'Custom field removed. Values already saved under this key are kept, and reappear if you create the field again.',
        ]);
    }

    /**
     * What the builder's pickers need: the entities a field can hang off, and
     * the modules one can be tagged with. Both in a single call, so the screen
     * does not have to reach for the public onboarding catalogue to draw a
     * dropdown.
     */
    public function entities()
    {
        return response()->json([
            'data' => CustomFieldEntities::options(),
            'modules' => Modules::options(),
        ]);
    }

    /**
     * The render-ready schema for one entity (or every entity at once).
     *
     * Already narrowed to active fields the tenant's modules allow, so the
     * frontend renders what it is given without filtering.
     */
    public function schema(Request $request)
    {
        $entity = $request->query('entity');

        if ($entity !== null) {
            abort_unless(CustomFieldEntities::exists($entity), 404, 'Unknown entity.');

            return response()->json(['data' => $this->fields->definitions($entity)]);
        }

        $all = [];
        foreach (CustomFieldEntities::keys() as $key) {
            $all[$key] = $this->fields->definitions($key);
        }

        return response()->json(['data' => $all]);
    }
}
