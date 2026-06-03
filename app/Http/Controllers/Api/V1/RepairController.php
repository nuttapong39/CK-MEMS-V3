<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRepairTicketRequest;
use App\Http\Requests\TransitionRepairRequest;
use App\Http\Resources\RepairTicketResource;
use App\Models\Equipment;
use App\Models\FlexMessageTemplate;
use App\Models\RepairTicket;
use App\Services\MophAlertService;
use App\Services\RepairWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class RepairController extends Controller
{
    public function __construct(private readonly RepairWorkflowService $workflow)
    {
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = RepairTicket::with([
            'equipment.department:id,code,name_th',
            'location:id,name',
            'reporter:id,name,full_name,department_id',
            'assignee:id,name,full_name',
        ])->where('hospital_id', $user->hospital_id);

        // "user" role can only see their own tickets
        if ($user->hasRole('user') && ! $user->hasAnyRole(['admin', 'staff'])) {
            $query->where('reported_by', $user->id);
        }

        if ($request->filled('status')) {
            $statuses = explode(',', $request->string('status'));
            $query->whereIn('status', $statuses);
        }
        if ($request->filled('urgency')) {
            $query->where('urgency', $request->string('urgency'));
        }
        if ($request->filled('equipment_id')) {
            $query->where('equipment_id', $request->integer('equipment_id'));
        }
        if ($request->filled('search')) {
            $term = '%'.$request->string('search').'%';
            $query->where(function ($q) use ($term) {
                $q->where('ticket_no', 'like', $term)
                  ->orWhere('symptom', 'like', $term)
                  ->orWhereHas('equipment', fn ($eq) => $eq
                      ->where('id_code', 'like', $term)
                      ->orWhere('name_th', 'like', $term));
            });
        }

        $sort = $request->string('sort', 'reported_at')->value();
        $dir = $request->string('dir', 'desc')->value() === 'asc' ? 'asc' : 'desc';
        if (in_array($sort, ['reported_at', 'ticket_no', 'urgency', 'status', 'created_at'])) {
            $query->orderBy($sort, $dir);
        }

        $perPage = min($request->integer('per_page', 25), 100);

        return RepairTicketResource::collection($query->paginate($perPage));
    }

    public function show(RepairTicket $ticket, Request $request): RepairTicketResource
    {
        abort_if($ticket->hospital_id !== $request->user()->hospital_id, 404);

        $ticket->load([
            'equipment.department',
            'location',
            'reporter:id,name,full_name,department_id',
            'assignee:id,name,full_name',
            'verifier:id,name,full_name',
            'progressLogs.user:id,name,full_name',
        ]);

        return new RepairTicketResource($ticket);
    }

    public function store(StoreRepairTicketRequest $request): JsonResponse
    {
        $user = $request->user();
        $data = $request->validated();

        $equipment = Equipment::where('hospital_id', $user->hospital_id)
            ->findOrFail($data['equipment_id']);

        $reportedAt = \Carbon\Carbon::parse($data['reported_at']);

        $ticket = RepairTicket::create([
            'hospital_id' => $user->hospital_id,
            'ticket_no'   => $this->workflow->generateTicketNumber($user->hospital_id),
            'equipment_id' => $equipment->id,
            'location_id'  => $data['location_id'] ?? $equipment->location_id,
            'reported_at'  => $reportedAt,
            'reported_by'  => $user->id,
            'symptom'      => $data['symptom'],
            'urgency'      => $data['urgency'],
            'status'       => RepairTicket::STATUS_PENDING,
            'sla_due_at'   => $this->workflow->calculateSlaDue($data['urgency'], $reportedAt),
        ]);

        // Initial log
        $ticket->progressLogs()->create([
            'from_status' => null,
            'to_status' => RepairTicket::STATUS_PENDING,
            'note' => 'แจ้งซ่อมโดย '.($user->full_name ?? $user->name),
            'changed_by' => $user->id,
            'changed_at' => now(),
        ]);

        $this->workflow->syncEquipmentStatus($ticket);

        $ticket->load(['equipment.department', 'location', 'reporter', 'progressLogs.user']);

        app(MophAlertService::class)->notify(
            $user->hospital,
            FlexMessageTemplate::KEY_REPAIR_REQUEST,
            app(MophAlertService::class)->buildRepairVariables($ticket),
            'repair.requested:'.$ticket->id,
        );

        return response()->json(new RepairTicketResource($ticket), 201);
    }

    public function transition(TransitionRepairRequest $request, RepairTicket $ticket): RepairTicketResource
    {
        abort_if($ticket->hospital_id !== $request->user()->hospital_id, 404);

        $user = $request->user();
        $data = $request->validated();
        $toStatus = $data['to_status'];

        // VERIFIED may be performed by the original reporter; all other transitions require admin/staff
        if ($toStatus === RepairTicket::STATUS_VERIFIED) {
            abort_if(
                $ticket->reported_by !== $user->id && ! $user->hasAnyRole(['admin', 'staff']),
                403, 'เฉพาะผู้แจ้งซ่อมหรือเจ้าหน้าที่เท่านั้น'
            );
        } else {
            abort_if(! $user->hasAnyRole(['admin', 'staff']), 403, 'ไม่มีสิทธิ์');
        }

        $extra = [];
        foreach ([
            'assigned_to', 'root_cause', 'action_taken', 'parts_used', 'repair_cost',
            'vendor_name', 'outsource_ref', 'expected_return_at',
            'decommission', 'decommission_reason',
        ] as $k) {
            if (array_key_exists($k, $data)) $extra[$k] = $data[$k];
        }

        $updated = $this->workflow->transition(
            $ticket,
            $toStatus,
            $user,
            $data['note'] ?? null,
            $extra,
        );
        $updated->load(['equipment.department', 'location', 'reporter', 'assignee', 'verifier', 'progressLogs.user']);

        $tplMap = [
            RepairTicket::STATUS_ACKNOWLEDGED => FlexMessageTemplate::KEY_REPAIR_ACKNOWLEDGED,
            RepairTicket::STATUS_IN_PROGRESS  => FlexMessageTemplate::KEY_REPAIR_IN_PROGRESS,
            RepairTicket::STATUS_REPAIRED     => FlexMessageTemplate::KEY_REPAIR_COMPLETED,
        ];
        if (isset($tplMap[$updated->status])) {
            $moph    = app(MophAlertService::class);
            $tplKey  = $tplMap[$updated->status];
            $vars    = $moph->buildRepairVariables($updated);

            // 1) Hospital-wide notification (admin/staff LINE group)
            $moph->notify(
                $request->user()->hospital,
                $tplKey,
                $vars,
                'repair.transition:'.$updated->id.':'.$updated->status,
            );

            // 2) Personal notification to the ticket reporter (using their own MOPH keys)
            $moph->notifyReporter($updated, $tplKey, $vars);
        }

        return new RepairTicketResource($updated);
    }

    public function destroy(RepairTicket $ticket, Request $request): JsonResponse
    {
        abort_if($ticket->hospital_id !== $request->user()->hospital_id, 404);

        // Permanently remove the ticket (progress logs cascade via FK).
        // Used by staff/admin to clean up duplicate / test / wrong reports.
        $equipment = $ticket->equipment;
        $ticket->delete();

        // If the equipment was marked UNDER_REPAIR only because of this ticket,
        // release it back to ACTIVE when no other open tickets remain.
        if ($equipment && $equipment->status === Equipment::STATUS_UNDER_REPAIR) {
            $hasOpen = RepairTicket::where('equipment_id', $equipment->id)
                ->whereIn('status', RepairWorkflowService::ACTIVE_STATUSES)
                ->exists();
            if (! $hasOpen) {
                $equipment->update(['status' => Equipment::STATUS_ACTIVE]);
            }
        }

        return response()->json(['message' => 'ลบงานซ่อมเรียบร้อย']);
    }

    public function nextOutsourceRef(Request $request): JsonResponse
    {
        $ref = $this->workflow->generateOutsourceRef($request->user()->hospital_id);
        return response()->json(['ref' => $ref]);
    }

    public function summary(Request $request): JsonResponse
    {
        $hospitalId = $request->user()->hospital_id;
        $byStatus = RepairTicket::where('hospital_id', $hospitalId)
            ->selectRaw('status, COUNT(*) as cnt')
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $total = RepairTicket::where('hospital_id', $hospitalId)->count();
        $closed = (int) ($byStatus->get(RepairTicket::STATUS_CLOSED) ?? 0);
        $cancelled = (int) ($byStatus->get(RepairTicket::STATUS_CANCELLED) ?? 0);

        return response()->json([
            'total' => $total,
            'by_status' => $byStatus,
            'open' => collect(RepairWorkflowService::ACTIVE_STATUSES)
                ->sum(fn ($s) => (int) ($byStatus->get($s) ?? 0)),
            // งานที่ยังไม่จบ = ทุกสถานะยกเว้น ปิดงาน (CLOSED) และ ยกเลิก (CANCELLED)
            'unfinished' => max(0, $total - $closed - $cancelled),
        ]);
    }
}
