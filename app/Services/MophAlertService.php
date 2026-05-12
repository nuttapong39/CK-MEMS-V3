<?php

namespace App\Services;

use App\Models\FlexMessageTemplate;
use App\Models\Hospital;
use App\Models\NotificationLog;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MophAlertService
{
    public function __construct(private readonly FlexMessageBuilder $flexBuilder)
    {
    }

    /**
     * Try to dispatch a MOPH alert. Failures are logged and never throw.
     */
    public function notify(
        Hospital $hospital,
        string $templateKey,
        array $variables = [],
        ?string $eventSignature = null,
        ?string $textOverride = null
    ): bool {
        $setting = $hospital->mophAlertSetting;

        if (! $setting || ! $setting->is_enabled) {
            return false;
        }

        // Per-event toggles
        $toggleMap = [
            FlexMessageTemplate::KEY_CREATE_EQUIPMENT    => 'notify_on_create_equipment',
            FlexMessageTemplate::KEY_REPAIR_REQUEST      => 'notify_on_repair_request',
            FlexMessageTemplate::KEY_REPAIR_ACKNOWLEDGED => 'notify_on_repair_progress',
            FlexMessageTemplate::KEY_REPAIR_IN_PROGRESS  => 'notify_on_repair_progress',
            FlexMessageTemplate::KEY_REPAIR_COMPLETED    => 'notify_on_repair_progress',
            FlexMessageTemplate::KEY_CALIBRATION_DONE   => 'notify_on_calibration',
            FlexMessageTemplate::KEY_CALIBRATION_DUE    => 'notify_on_calibration',
        ];
        $toggleField = $toggleMap[$templateKey] ?? null;
        if ($toggleField && ! $setting->{$toggleField}) {
            return false;
        }

        $template = FlexMessageTemplate::where('hospital_id', $hospital->id)
            ->where('key', $templateKey)
            ->where('is_active', true)
            ->first();

        if (! $template) {
            $this->logFailure($hospital->id, $templateKey, $eventSignature, ['reason' => 'template_not_found']);
            return false;
        }

        try {
            $rendered = $this->flexBuilder->render($template->json_payload, $variables);
            $textMsg  = $textOverride ?? $template->name;
            $payload  = $this->flexBuilder->buildPayload($rendered, $textMsg, $template->alt_text);
        } catch (\Throwable $e) {
            $this->logFailure($hospital->id, $templateKey, $eventSignature, [
                'reason'  => 'render_error',
                'message' => $e->getMessage(),
            ]);
            return false;
        }

        try {
            $url = $this->normalizeEndpointUrl($setting->endpoint_url);

            $response = Http::withHeaders([
                    'client-key'   => $setting->client_key,
                    'secret-key'   => $setting->secret_key,
                    'Content-Type' => 'application/json',
                ])
                ->timeout(10)
                ->post($url, $payload);

            NotificationLog::create([
                'hospital_id'      => $hospital->id,
                'template_key'     => $templateKey,
                'event_signature'  => $eventSignature,
                'payload_snapshot' => $payload,
                'response_code'    => $response->status(),
                'response_body'    => substr($response->body(), 0, 4000),
                'sent_at'          => now(),
            ]);

            return $response->successful();
        } catch (\Throwable $e) {
            $this->logFailure($hospital->id, $templateKey, $eventSignature, [
                'reason'  => 'http_error',
                'message' => $e->getMessage(),
            ]);
            Log::warning('MophAlert HTTP error: '.$e->getMessage(), ['template' => $templateKey]);
            return false;
        }
    }

    /**
     * Send a synthetic test alert using current settings.
     */
    public function sendTest(Hospital $hospital): array
    {
        $setting = $hospital->mophAlertSetting;
        if (! $setting) {
            return ['ok' => false, 'message' => 'ยังไม่ได้ตั้งค่า MOPH Alert'];
        }

        $payload = [
            'messages' => [
                ['type' => 'text', 'text' => '✅ [ทดสอบระบบ CK-MEMS] '.$hospital->name_th],
            ],
        ];

        try {
            $url = $this->normalizeEndpointUrl($setting->endpoint_url);

            $response = Http::withHeaders([
                    'client-key'   => $setting->client_key,
                    'secret-key'   => $setting->secret_key,
                    'Content-Type' => 'application/json',
                ])
                ->timeout(10)
                ->post($url, $payload);

            $setting->update([
                'last_test_at'     => now(),
                'last_test_status' => $response->successful()
                    ? 'OK ('.$response->status().')'
                    : 'FAILED ('.$response->status().')',
            ]);

            NotificationLog::create([
                'hospital_id'      => $hospital->id,
                'template_key'     => 'TEST',
                'event_signature'  => 'manual_test',
                'payload_snapshot' => $payload,
                'response_code'    => $response->status(),
                'response_body'    => substr($response->body(), 0, 4000),
                'sent_at'          => now(),
            ]);

            return [
                'ok'          => $response->successful(),
                'status_code' => $response->status(),
                'body'        => substr($response->body(), 0, 500),
            ];
        } catch (\Throwable $e) {
            $setting->update(['last_test_at' => now(), 'last_test_status' => 'ERROR: '.$e->getMessage()]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }
    }

    // ─────────────────────────────────────────────────────
    //  Variable builders
    // ─────────────────────────────────────────────────────

    public function buildEquipmentVariables($equipment): array
    {
        $equipment->loadMissing('department');

        return [
            'equipment' => [
                'id'            => $equipment->id,
                'id_code'       => $equipment->id_code,
                'name_th'       => $equipment->name_th,
                'name_en'       => $equipment->name_en ?? '',
                'manufacturer'  => $equipment->manufacturer ?? '',
                'model'         => $equipment->model ?? '',
                'serial_number' => $equipment->serial_number ?? '',
                'department'    => $equipment->department?->name_th ?? '',
                'fiscal_year'   => $equipment->fiscal_year ?? '',
            ],
            'system' => [
                'equipment_url' => url('/equipments/'.$equipment->id),
                'app_name'      => 'CK-MEMS',
            ],
        ];
    }

    public function buildRepairVariables($ticket): array
    {
        $ticket->loadMissing(['equipment.department', 'reporter', 'assignee']);

        $urgencyLabel = match ($ticket->urgency) {
            'CRITICAL' => '🔴 วิกฤต',
            'HIGH'     => '🟠 เร่งด่วน',
            'MEDIUM'   => '🟡 ปานกลาง',
            default    => '🟢 ปกติ',
        };

        $statusLabel = match ($ticket->status) {
            'PENDING'      => '⏳ รอดำเนินการ',
            'ACKNOWLEDGED' => '🔔 รับเรื่องแล้ว',
            'IN_PROGRESS'  => '🔧 กำลังดำเนินการ',
            'REPAIRED'     => '✅ ซ่อมเสร็จแล้ว',
            'VERIFIED'     => '✔️ ตรวจรับแล้ว',
            'CLOSED'       => '🔒 ปิดงาน',
            default        => $ticket->status,
        };

        return [
            ...$this->buildEquipmentVariables($ticket->equipment),
            'ticket' => [
                'ticket_no'       => $ticket->ticket_no,
                'symptom'         => $ticket->symptom,
                'urgency'         => $urgencyLabel,
                'status'          => $ticket->status,
                'status_label'    => $statusLabel,
                'reported_at'     => $ticket->reported_at?->format('d/m/Y H:i') ?? '',
                'reporter_name'   => $ticket->reporter?->full_name ?? $ticket->reporter?->name ?? '',
                'assignee_name'   => $ticket->assignee?->full_name ?? $ticket->assignee?->name ?? '-',
                // Progress fields
                'root_cause'      => $ticket->root_cause ?? '-',
                'action_taken'    => $ticket->action_taken ?? '-',
                'parts_used'      => $ticket->parts_used ?? '-',
                'repair_cost'     => $ticket->repair_cost ? number_format((float)$ticket->repair_cost, 2).' บาท' : '-',
                'vendor_name'     => $ticket->vendor_name ?? '-',
                // Timeline
                'acknowledged_at' => $ticket->acknowledged_at?->format('d/m/Y H:i') ?? '-',
                'started_at'      => $ticket->started_at?->format('d/m/Y H:i') ?? '-',
                'completed_at'    => $ticket->completed_at?->format('d/m/Y H:i') ?? '-',
            ],
        ];
    }

    public function buildCalibrationVariables($calibration): array
    {
        $calibration->loadMissing(['equipment.department', 'creator']);

        $isPass        = $calibration->result === 'PASS';
        $calibratedAt  = $calibration->calibrated_at;
        $nextDueAt     = $calibration->next_due_at;
        $daysRemaining = $nextDueAt ? (int) now()->diffInDays($nextDueAt, false) : null;

        // Result styling
        $resultLabel      = $isPass ? 'ผ่าน' : 'ไม่ผ่าน';
        $resultIcon       = $isPass ? '✅' : '❌';
        $resultBgColor    = $isPass ? '#ECFDF5' : '#FEF2F2';
        $resultTextColor  = $isPass ? '#16A34A' : '#DC2626';

        // Days remaining colour
        $daysColor = '#16A34A'; // green — safe
        if ($daysRemaining !== null) {
            if ($daysRemaining <= 30)       $daysColor = '#DC2626'; // red
            elseif ($daysRemaining <= 90)   $daysColor = '#D97706'; // amber
        }

        // Warning block
        [$warnIcon, $warnTitle, $warnBg, $warnTextColor, $warnSubColor] = $this->warningBlock($daysRemaining, $nextDueAt);

        return [
            ...$this->buildEquipmentVariables($calibration->equipment),
            'calibration' => [
                // Core data — matched to template variable names
                'result'            => $resultLabel,
                'result_icon'       => $resultIcon,
                'result_bg_color'   => $resultBgColor,
                'result_text_color' => $resultTextColor,

                // Dates
                'calibrated_date'   => $calibratedAt?->format('d/m/Y') ?? '',
                'next_due_date'     => $nextDueAt?->format('d/m/Y') ?? '',
                'days_remaining'    => $daysRemaining !== null ? (string) $daysRemaining : '-',
                'days_color'        => $daysColor,

                // Personnel & agency
                'agency'            => $calibration->organization ?? '',
                'calibrated_by'     => $calibration->calibrator_name ?? ($calibration->creator?->full_name ?? ''),

                // Warning block
                'warning_icon'      => $warnIcon,
                'warning_title'     => $warnTitle,
                'warning_bg_color'  => $warnBg,
                'warning_text_color' => $warnTextColor,
                'warning_sub_color' => $warnSubColor,

                // Legacy aliases (keep old keys for backwards-compat)
                'organization'      => $calibration->organization ?? '',
                'calibrated_at'     => $calibratedAt?->format('d/m/Y') ?? '',
                'next_due_at'       => $nextDueAt?->format('d/m/Y') ?? '',
            ],
            'system' => [
                'equipment_url' => url('/equipments/'.$calibration->equipment_id),
                'app_name'      => 'CK-MEMS',
            ],
        ];
    }

    // ─────────────────────────────────────────────────────
    //  Helpers
    // ─────────────────────────────────────────────────────

    /**
     * Normalize endpoint URL: strip duplicate ?messages=yes, then append once.
     */
    private function normalizeEndpointUrl(string $rawUrl): string
    {
        // Remove all occurrences of messages=yes from query string
        $clean = preg_replace('/[?&]messages=yes/', '', rtrim($rawUrl, '?&'));
        $sep   = str_contains($clean, '?') ? '&' : '?';

        return $clean.$sep.'messages=yes';
    }

    /**
     * Determine warning block content based on days remaining until next calibration.
     */
    private function warningBlock(?int $days, $nextDueAt): array
    {
        if ($days === null || $nextDueAt === null) {
            return ['📋', 'ไม่ระบุวันสอบเทียบครั้งถัดไป', '#F8FAFC', '#64748B', '#94A3B8'];
        }

        if ($days <= 0) {
            return ['🔴', 'เกินกำหนดสอบเทียบแล้ว!', '#FEF2F2', '#DC2626', '#EF4444'];
        }

        if ($days <= 30) {
            return ['🔴', 'ถึงกำหนดสอบเทียบเร่งด่วน!', '#FEF2F2', '#DC2626', '#EF4444'];
        }

        if ($days <= 90) {
            return ['⚠️', 'ใกล้ถึงกำหนดสอบเทียบแล้ว', '#FFFBEB', '#D97706', '#F59E0B'];
        }

        return ['✅', 'สอบเทียบแล้ว ยังไม่ถึงกำหนด', '#F0FDF4', '#16A34A', '#22C55E'];
    }

    private function logFailure(int $hospitalId, string $templateKey, ?string $eventSignature, array $reason): void
    {
        NotificationLog::create([
            'hospital_id'      => $hospitalId,
            'template_key'     => $templateKey,
            'event_signature'  => $eventSignature,
            'payload_snapshot' => $reason,
            'response_code'    => null,
            'response_body'    => json_encode($reason, JSON_UNESCAPED_UNICODE),
            'sent_at'          => now(),
        ]);
    }
}
