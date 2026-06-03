<?php

namespace App\Http\Resources;

use App\Services\RepairWorkflowService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RepairTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'ticket_no'   => $this->ticket_no,
            'reported_at' => $this->reported_at,
            'sla_due_at'  => $this->sla_due_at,
            'status'      => $this->status,
            'urgency'     => $this->urgency,
            'symptom'     => $this->symptom,
            'root_cause'  => $this->root_cause,
            'action_taken' => $this->action_taken,
            'parts_used'   => $this->parts_used,
            'repair_cost'  => $this->repair_cost,
            'vendor_name'        => $this->vendor_name,
            'outsource_ref'      => $this->outsource_ref,
            'outsourced_at'      => $this->outsourced_at,
            'expected_return_at' => $this->expected_return_at,
            'acknowledged_at' => $this->acknowledged_at,
            'started_at'      => $this->started_at,
            'completed_at'    => $this->completed_at,
            'verified_at'     => $this->verified_at,
            'closed_at'       => $this->closed_at,
            'sla_overdue'     => $this->sla_due_at && now()->gt($this->sla_due_at)
                                    && ! in_array($this->status, ['CLOSED', 'CANCELLED']),
            'next_statuses'   => app(RepairWorkflowService::class)->nextStatuses($this->resource),
            'equipment' => $this->whenLoaded('equipment', fn () => $this->equipment ? [
                'id' => $this->equipment->id,
                'id_code' => $this->equipment->id_code,
                'name_th' => $this->equipment->name_th,
                'manufacturer' => $this->equipment->manufacturer,
                'model' => $this->equipment->model,
                'department' => $this->equipment->department ? [
                    'id' => $this->equipment->department->id,
                    'code' => $this->equipment->department->code,
                    'name_th' => $this->equipment->department->name_th,
                ] : null,
            ] : null),
            'location' => $this->whenLoaded('location', fn () => $this->location ? [
                'id' => $this->location->id,
                'name' => $this->location->name,
            ] : null),
            'reporter_name' => $this->reporter_name,
            'reporter' => $this->whenLoaded('reporter', fn () => $this->reporter ? [
                'id' => $this->reporter->id,
                'full_name' => $this->reporter->full_name ?? $this->reporter->name,
                'department' => $this->reporter->department?->name_th,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'full_name' => $this->assignee->full_name ?? $this->assignee->name,
            ] : null),
            'verifier' => $this->whenLoaded('verifier', fn () => $this->verifier ? [
                'id' => $this->verifier->id,
                'full_name' => $this->verifier->full_name ?? $this->verifier->name,
            ] : null),
            'progress_logs' => $this->whenLoaded('progressLogs', fn () => $this->progressLogs->map(fn ($log) => [
                'id' => $log->id,
                'from_status' => $log->from_status,
                'to_status' => $log->to_status,
                'note' => $log->note,
                'changed_at' => $log->changed_at,
                'changed_by' => $log->user ? ($log->user->full_name ?? $log->user->name) : null,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
