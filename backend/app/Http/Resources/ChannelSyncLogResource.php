<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChannelSyncLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel_integration_id' => $this->channel_integration_id,
            'integration' => $this->whenLoaded('integration', fn () => [
                'id' => $this->integration->id,
                'name' => $this->integration->name,
                'platform' => $this->integration->platform,
            ]),
            'type' => $this->type,
            'direction' => $this->direction,
            'status' => $this->status,
            'processed' => $this->processed,
            'succeeded' => $this->succeeded,
            'failed' => $this->failed,
            'message' => $this->message,
            'created_at' => $this->created_at,
        ];
    }
}
