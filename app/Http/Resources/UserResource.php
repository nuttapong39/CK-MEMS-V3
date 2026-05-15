<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'employee_code' => $this->employee_code,
            'username'      => $this->username,
            'full_name'     => $this->full_name ?? $this->name,
            'name'          => $this->name,
            'email'         => $this->email,
            'phone'         => $this->phone,
            'avatar_path'   => $this->avatar_path,
            'avatar_url'    => $this->avatar_path ? asset('storage/'.$this->avatar_path) : null,
            'is_active'     => $this->is_active,
            'last_login_at' => $this->last_login_at,
            // MOPH Alert personal keys (secret_key แสดงเฉพาะว่ามีหรือไม่)
            'moph_client_key'     => $this->moph_client_key,
            'moph_has_secret_key' => ! empty($this->moph_secret_key),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'hospital' => $this->whenLoaded('hospital', fn () => [
                'id'      => $this->hospital->id,
                'code'    => $this->hospital->code,
                'name_th' => $this->hospital->name_th,
            ]),
            'department' => $this->whenLoaded('department', fn () => $this->department ? [
                'id'      => $this->department->id,
                'code'    => $this->department->code,
                'name_th' => $this->department->name_th,
            ] : null),
        ];
    }
}
