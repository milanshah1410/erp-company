<?php

namespace App\Http\Resources\Concerns;

use App\Support\TenantStorage;

/**
 * Shared serialization of the approval-workflow + payment + attachment fields
 * common to every Expenses sub-module resource.
 */
trait SerializesWorkflowFields
{
    protected function workflowFields(): array
    {
        return [
            'payment_method' => $this->payment_method,
            'reference_no' => $this->reference_no,
            'description' => $this->description,
            'status' => $this->status,
            'attachment_path' => $this->attachment_path,
            'attachment_url' => TenantStorage::temporaryUrl($this->attachment_path),
            'paid_at' => $this->paid_at,
            'is_paid' => $this->paid_at !== null,
            'rejection_reason' => $this->rejection_reason,
            'creator' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'approver' => $this->whenLoaded('approver', fn () => $this->approver?->name),
            'approved_at' => $this->approved_at,
            'created_at' => $this->created_at,
        ];
    }
}
