<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ShipmentDocumentResource;
use App\Models\Shipment;
use App\Models\ShipmentDocument;
use App\Support\TenantStorage;
use App\Support\TradeCatalogue;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Trade documents per shipment — the Pending Documents board (SRS §24).
 *
 * A missing B/L or certificate of origin holds up clearance and, on an L/C, the
 * payment behind it, so the list defaults to what is still outstanding and flags
 * anything past its due date. Scanned copies go to the tenant's private storage
 * folder and are handed back only as short-lived signed URLs.
 */
class ShipmentDocumentController extends Controller
{
    public function index(Request $request)
    {
        $query = ShipmentDocument::query()
            ->with([
                // The FKs stay in the select so the party names come along in the
                // same two queries instead of one per row.
                'shipment:id,shipment_number,direction,status,eta,supplier_id,customer_id',
                'shipment.supplier:id,name', 'shipment.customer:id,name',
                'uploader:id,name',
            ]);

        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('doc_number', 'like', "%{$search}%")
                    ->orWhere('doc_type', 'like', "%{$search}%")
                    ->orWhereHas('shipment', fn ($s) => $s->where('shipment_number', 'like', "%{$search}%"));
            });
        }
        foreach (['shipment_id', 'status', 'doc_type'] as $filter) {
            if ($request->filled($filter)) {
                $query->where($filter, $request->query($filter));
            }
        }
        if ($request->filled('direction')) {
            $query->whereHas('shipment', fn ($s) => $s->where('direction', $request->query('direction')));
        }
        // Default view: what someone still has to chase.
        if (! $request->filled('status') && $request->query('state', 'outstanding') === 'outstanding') {
            $query->outstanding();
        }
        if ($request->boolean('overdue_only')) {
            $query->outstanding()
                ->whereNotNull('due_date')
                ->whereDate('due_date', '<', now()->toDateString());
        }

        // Overdue first, then by due date — the board is a work queue.
        $query->orderByRaw('due_date is null')->orderBy('due_date')->orderByDesc('id');
        $perPage = min((int) $request->query('per_page', 15), 100);

        return ShipmentDocumentResource::collection($query->paginate($perPage));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $shipment = Shipment::findOrFail($data['shipment_id']);

        $data['status'] = $data['status'] ?? 'pending';
        $data['is_mandatory'] = $data['is_mandatory'] ?? true;

        $document = $shipment->documents()->create($data + [
            'uploaded_by' => $request->user()?->id,
        ]);

        return (new ShipmentDocumentResource($document->load(['shipment:id,shipment_number,direction', 'uploader:id,name'])))
            ->response()->setStatusCode(201);
    }

    public function show(ShipmentDocument $shipmentDocument)
    {
        return new ShipmentDocumentResource(
            $shipmentDocument->load(['shipment:id,shipment_number,direction', 'uploader:id,name'])
        );
    }

    public function update(Request $request, ShipmentDocument $shipmentDocument)
    {
        $data = $this->validated($request, $shipmentDocument);
        unset($data['shipment_id']);
        foreach (['status', 'is_mandatory'] as $key) {
            if (array_key_exists($key, $data) && $data[$key] === null) {
                unset($data[$key]); // absent/blank means "leave as is"
            }
        }

        $wasOutstanding = in_array($shipmentDocument->status, ShipmentDocument::OPEN_STATUSES, true);
        $shipmentDocument->update($data);

        // Receiving a document is a milestone the timeline should carry.
        if ($wasOutstanding && ! in_array($shipmentDocument->status, ShipmentDocument::OPEN_STATUSES, true)) {
            $shipmentDocument->shipment?->logEvent('document_received', [
                'description' => $shipmentDocument->label().' '.$shipmentDocument->status,
                'created_by' => $request->user()?->id,
            ]);
        }

        return new ShipmentDocumentResource(
            $shipmentDocument->load(['shipment:id,shipment_number,direction', 'uploader:id,name'])
        );
    }

    public function destroy(ShipmentDocument $shipmentDocument)
    {
        if ($shipmentDocument->file_path) {
            TenantStorage::disk()->delete($shipmentDocument->file_path);
        }
        $shipmentDocument->delete();

        return response()->json(['message' => 'Document removed.']);
    }

    /** Upload (or replace) the scanned copy. */
    public function upload(Request $request, ShipmentDocument $shipmentDocument)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx,xls,xlsx', 'max:10240'],
        ]);

        $disk = TenantStorage::disk();
        if ($shipmentDocument->file_path) {
            $disk->delete($shipmentDocument->file_path);
        }
        $path = $disk->putFile('shipment-documents', $request->file('file'));

        DB::transaction(function () use ($shipmentDocument, $path, $request) {
            $shipmentDocument->update([
                'file_path' => $path,
                // An uploaded copy means the document is in hand.
                'status' => in_array($shipmentDocument->status, ['pending', 'rejected'], true)
                    ? 'received'
                    : $shipmentDocument->status,
                'uploaded_by' => $request->user()?->id,
            ]);
            $shipmentDocument->shipment?->logEvent('document_received', [
                'description' => $shipmentDocument->label().' uploaded',
                'created_by' => $request->user()?->id,
            ]);
        });

        return new ShipmentDocumentResource(
            $shipmentDocument->load(['shipment:id,shipment_number,direction', 'uploader:id,name'])
        );
    }

    /** Delete just the attachment, keeping the checklist row. */
    public function deleteFile(ShipmentDocument $shipmentDocument)
    {
        if ($shipmentDocument->file_path) {
            TenantStorage::disk()->delete($shipmentDocument->file_path);
            $shipmentDocument->update(['file_path' => null]);
        }

        return new ShipmentDocumentResource($shipmentDocument->load('shipment:id,shipment_number,direction'));
    }

    /** Outstanding / overdue counts for the board's summary strip. */
    public function summary(Request $request)
    {
        $base = fn () => ShipmentDocument::query()
            ->when($request->filled('direction'), fn ($q) => $q->whereHas(
                'shipment',
                fn ($s) => $s->where('direction', $request->query('direction')),
            ));

        $today = now()->toDateString();

        return response()->json([
            'outstanding' => $base()->outstanding()->count(),
            'overdue' => $base()->outstanding()->whereNotNull('due_date')->whereDate('due_date', '<', $today)->count(),
            'due_this_week' => $base()->outstanding()->whereBetween('due_date', [$today, now()->addDays(7)->toDateString()])->count(),
            'received' => $base()->whereIn('status', ['received', 'verified'])->count(),
        ]);
    }

    private function validated(Request $request, ?ShipmentDocument $existing = null): array
    {
        return $request->validate([
            'shipment_id' => [$existing ? 'nullable' : 'required', 'exists:shipments,id'],
            'doc_type' => ['required', Rule::in(array_keys(TradeCatalogue::DOCUMENTS))],
            'doc_number' => ['nullable', 'string', 'max:255'],
            'doc_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date'],
            'status' => ['nullable', Rule::in(['pending', 'received', 'submitted', 'verified', 'rejected'])],
            'is_mandatory' => ['nullable', 'boolean'],
            'remarks' => ['nullable', 'string'],
        ]);
    }
}
