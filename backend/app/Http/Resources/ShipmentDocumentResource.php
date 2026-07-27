<?php

namespace App\Http\Resources;

use App\Support\TenantStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShipmentDocumentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipment_id,
            'shipment_number' => $this->whenLoaded('shipment', fn () => $this->shipment?->shipment_number),
            'direction' => $this->whenLoaded('shipment', fn () => $this->shipment?->direction),
            'party' => $this->whenLoaded('shipment', fn () => $this->shipment?->partyName()),
            'eta' => $this->whenLoaded('shipment', fn () => $this->shipment?->eta?->toDateString()),
            'doc_type' => $this->doc_type,
            'doc_label' => $this->label(),
            'doc_number' => $this->doc_number,
            'doc_date' => $this->doc_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'status' => $this->status,
            'is_mandatory' => $this->is_mandatory,
            'is_overdue' => $this->isOverdue(),
            'file_path' => $this->file_path,
            // Short-lived signed URL; the file itself stays private on the
            // tenant's own storage folder.
            'file_url' => TenantStorage::temporaryUrl($this->file_path),
            'remarks' => $this->remarks,
            'uploader' => $this->whenLoaded('uploader', fn () => $this->uploader?->name),
            'created_at' => $this->created_at,
        ];
    }
}
