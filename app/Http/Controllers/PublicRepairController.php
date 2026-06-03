<?php

namespace App\Http\Controllers;

use App\Models\Equipment;
use App\Models\FlexMessageTemplate;
use App\Models\RepairTicket;
use App\Services\MophAlertService;
use App\Services\RepairWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Public (no-auth) repair reporting via QR scan.
 *
 * The repair QR encodes /qr/repair/{idCode}. Anyone scanning it can submit a
 * repair request for that specific equipment without logging in — the ticket is
 * created with reported_by = null and an optional free-text reporter name.
 */
class PublicRepairController extends Controller
{
    public function __construct(private readonly RepairWorkflowService $workflow)
    {
    }

    public function show(string $idCode)
    {
        $equipment = Equipment::with([
            'hospital:id,code,name_th',
            'department:id,code,name_th',
            'location:id,name',
        ])->where('id_code', $idCode)->firstOrFail();

        return view('qr.repair', compact('equipment'));
    }

    public function store(Request $request, string $idCode): JsonResponse
    {
        $equipment = Equipment::where('id_code', $idCode)->firstOrFail();

        if ($equipment->status === Equipment::STATUS_OUT_OF_SERVICE) {
            return response()->json([
                'message' => 'ไม่สามารถแจ้งซ่อมได้ เนื่องจากเครื่องมือนี้ถูกระบุว่าใช้งานไม่ได้แล้ว',
            ], 422);
        }

        $data = $request->validate([
            'reporter_name' => ['nullable', 'string', 'max:191'],
            'reported_at'   => ['required', 'date'],
            'symptom'       => ['required', 'string', 'min:3'],
            'urgency'       => ['required', Rule::in(['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])],
        ]);

        $reportedAt = \Carbon\Carbon::parse($data['reported_at']);
        $reporterName = $data['reporter_name'] ?: null;

        $ticket = RepairTicket::create([
            'hospital_id'   => $equipment->hospital_id,
            'ticket_no'     => $this->workflow->generateTicketNumber($equipment->hospital_id),
            'equipment_id'  => $equipment->id,
            'location_id'   => $equipment->location_id,
            'reported_at'   => $reportedAt,
            'reported_by'   => null,
            'reporter_name' => $reporterName,
            'symptom'       => $data['symptom'],
            'urgency'       => $data['urgency'],
            'status'        => RepairTicket::STATUS_PENDING,
            'sla_due_at'    => $this->workflow->calculateSlaDue($data['urgency'], $reportedAt),
        ]);

        $ticket->progressLogs()->create([
            'from_status' => null,
            'to_status'   => RepairTicket::STATUS_PENDING,
            'note'        => 'แจ้งซ่อมผ่าน QR Code'.($reporterName ? ' โดย '.$reporterName : ''),
            'changed_by'  => null,
            'changed_at'  => now(),
        ]);

        $this->workflow->syncEquipmentStatus($ticket);

        $ticket->load(['equipment.department', 'location', 'progressLogs']);

        app(MophAlertService::class)->notify(
            $equipment->hospital,
            FlexMessageTemplate::KEY_REPAIR_REQUEST,
            app(MophAlertService::class)->buildRepairVariables($ticket),
            'repair.requested:'.$ticket->id,
        );

        return response()->json([
            'ticket_no' => $ticket->ticket_no,
            'message'   => 'แจ้งซ่อมเรียบร้อย',
        ], 201);
    }
}
