<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RoleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            // Level-2 only: a tenant `web` role with no tenant_id belongs to the
            // shared system catalogue; one carrying a tenant_id is that
            // workspace's own custom role. Level-1 central roles report neither.
            'scope' => $this->when(
                $this->guard_name === 'web',
                fn () => $this->tenant_id === null ? 'system' : 'custom'
            ),
            'is_system' => $this->when($this->guard_name === 'web', fn () => $this->tenant_id === null),
            'department' => $this->when(isset($this->department), fn () => $this->department),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')),
            'permissions_count' => $this->when(isset($this->permissions_count), fn () => $this->permissions_count),
            'users_count' => $this->when(isset($this->users_count), fn () => $this->users_count),
        ];
    }
}
