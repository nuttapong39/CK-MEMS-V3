<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EquipmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'id_code' => $this->id_code,
            'asset_number' => $this->asset_number,
            'fiscal_year' => $this->fiscal_year,
            'name_th' => $this->name_th,
            'name_en' => $this->name_en,
            'manufacturer' => $this->manufacturer,
            'model' => $this->model,
            'serial_number' => $this->serial_number,
            'maintenance_cycles_per_year' => $this->maintenance_cycles_per_year,
            'calibration_by' => $this->calibration_by,
            'status' => $this->status,
            'note' => $this->note,
            'purchase_date' => $this->purchase_date,
            'warranty_until' => $this->warranty_until,
            'department' => $this->whenLoaded('department', fn () => [
                'id' => $this->department->id,
                'code' => $this->department->code,
                'name_th' => $this->department->name_th,
            ]),
            'equipment_code' => $this->whenLoaded('equipmentCode', fn () => [
                'id' => $this->equipmentCode->id,
                'code' => $this->equipmentCode->code,
                'name_th' => $this->equipmentCode->name_th,
                'name_en' => $this->equipmentCode->name_en,
            ]),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ] : null),
            'responsible_user' => $this->whenLoaded('responsibleUser', fn () => $this->responsibleUser ? [
                'id' => $this->responsibleUser->id,
                'full_name' => $this->responsibleUser->full_name ?? $this->responsibleUser->name,
            ] : null),
            'latest_calibration' => $this->whenLoaded('latestCalibration', fn () => $this->latestCalibration ? [
                'calibrated_at' => $this->latestCalibration->calibrated_at,
                'next_due_at' => $this->latestCalibration->next_due_at,
                'result' => $this->latestCalibration->result,
            ] : null),
            'image_url' => $this->image_url,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
