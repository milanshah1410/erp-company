<?php

namespace App\Http\Resources;

use App\Support\TenantStorage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'expense_number' => $this->expense_number,
            'expense_category_id' => $this->expense_category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category?->name),
            'branch_id' => $this->branch_id,
            'project_id' => $this->project_id,
            'project' => $this->whenLoaded('project', fn () => $this->project ? [
                'id' => $this->project->id,
                'project_number' => $this->project->project_number,
                'name' => $this->project->name,
            ] : null),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'expense_date' => $this->expense_date?->toDateString(),
            'amount' => $this->amount,
            'tds_section' => $this->tds_section,
            'tds_rate' => (float) $this->tds_rate,
            'tds_amount' => (float) $this->tds_amount,
            'payment_method' => $this->payment_method,
            'payee' => $this->payee,
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
