<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => (bool) $this->is_active,
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'company_ids' => $this->whenLoaded('companies', fn () => $this->companies->pluck('id')),
            'branch_ids' => $this->whenLoaded('branches', fn () => $this->branches->pluck('id')),
            'created_at' => $this->created_at,
        ];
    }
}
