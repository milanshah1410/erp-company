<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'log_name' => $this->log_name,
            'event' => $this->event,
            'description' => $this->description,
            'subject_type' => $this->subject_type ? class_basename($this->subject_type) : null,
            'subject_id' => $this->subject_id,
            'causer' => $this->causer ? [
                'id' => $this->causer->id,
                'name' => $this->causer->name ?? $this->causer->email ?? "#{$this->causer->id}",
            ] : null,
            'properties' => [
                'old' => $this->properties['old'] ?? null,
                'attributes' => $this->properties['attributes'] ?? null,
                'extra' => $this->properties->except(['old', 'attributes'])->all() ?: null,
            ],
            'created_at' => $this->created_at,
        ];
    }
}
