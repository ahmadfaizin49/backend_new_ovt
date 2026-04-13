<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OvertimeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'user_id'       => $this->user_id,
            'tanggal'       => $this->tanggal?->format('Y-m-d'),
            'keterangan'    => $this->keterangan,
            'jam_lembur'    => $this->jam_lembur,
            'tarif_per_jam' => $this->tarif_per_jam,
            'total'         => $this->total,
            'created_at'    => $this->created_at?->toISOString(),
            'updated_at'    => $this->updated_at?->toISOString(),
        ];
    }
}
