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
            'department' => $this->when(isset($this->department), fn () => $this->department),
            'permissions' => $this->whenLoaded('permissions', fn () => $this->permissions->pluck('name')),
            'permissions_count' => $this->when(isset($this->permissions_count), fn () => $this->permissions_count),
            'users_count' => $this->when(isset($this->users_count), fn () => $this->users_count),
        ];
    }
}
