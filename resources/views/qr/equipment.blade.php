<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{{ $equipment->id_code }} — {{ $equipment->name_th }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root { --blue: #2563eb; --slate-50: #f8fafc; --slate-100: #f1f5f9; --slate-300: #cbd5e1; --slate-500: #64748b; --slate-700: #334155; --slate-800: #1e293b; }
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        body {
            margin: 0; padding: 0;
            font-family: 'IBM Plex Sans Thai', 'Sarabun', system-ui, sans-serif;
            background: linear-gradient(135deg, #dbeafe, #fff, #e0e7ff);
            min-height: 100vh; color: var(--slate-800);
        }
        .container { max-width: 480px; margin: 0 auto; padding: 24px 16px; }
        .card {
            background: white; border-radius: 18px; padding: 24px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
            border: 1px solid var(--slate-100);
        }
        .header {
            text-align: center; padding-bottom: 16px;
            border-bottom: 1px solid var(--slate-100);
        }
        .logo {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, #3b82f6, #6366f1);
            color: white; font-weight: 700; font-size: 18px;
            border-radius: 14px; display: inline-flex;
            align-items: center; justify-content: center;
            margin-bottom: 8px;
        }
        h1 { font-size: 16px; margin: 0; color: var(--blue); font-weight: 700; }
        .subtitle { font-size: 11px; color: var(--slate-500); margin-top: 2px; }
        .id_code {
            font-family: 'JetBrains Mono', monospace;
            font-size: 18px; font-weight: 700; color: var(--blue);
            text-align: center; margin: 16px 0; padding: 10px;
            background: linear-gradient(135deg, #dbeafe, #e0e7ff);
            border-radius: 12px;
        }
        .name_th { font-size: 22px; font-weight: 700; text-align: center; margin: 8px 0 4px; }
        .name_en { font-size: 13px; color: var(--slate-500); text-align: center; margin-bottom: 16px; }
        .badge {
            display: inline-block; padding: 4px 12px; border-radius: 999px;
            font-size: 11px; font-weight: 600;
        }
        .b-active { background: #d1fae5; color: #047857; }
        .b-broken { background: #fee2e2; color: #b91c1c; }
        .b-repair { background: #fef3c7; color: #b45309; }
        .b-other { background: #f1f5f9; color: var(--slate-500); }
        .field-grid {
            display: grid; grid-template-columns: 1fr 2fr;
            gap: 8px 16px; margin-top: 16px;
            padding-top: 16px; border-top: 1px solid var(--slate-100);
        }
        .field-label { font-size: 11px; color: var(--slate-500); }
        .field-value { font-size: 13px; color: var(--slate-700); font-weight: 500; }
        .footer { text-align: center; font-size: 11px; color: var(--slate-500); margin-top: 16px; }
        .equipment-image {
            width: 100%; max-height: 200px;
            object-fit: contain;
            border-radius: 12px;
            border: 1px solid var(--slate-100);
            background: var(--slate-50);
            margin-bottom: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <div class="logo">CK</div>
                <h1>CK-MEMS</h1>
                <div class="subtitle">{{ $equipment->hospital->name_th ?? '' }}</div>
            </div>

            @if ($equipment->image_url)
                <img src="{{ $equipment->image_url }}" alt="{{ $equipment->name_th }}" class="equipment-image" />
            @endif

            <div class="id_code">{{ $equipment->id_code }}</div>
            <div class="name_th">{{ $equipment->name_th }}</div>
            @if ($equipment->name_en)
                <div class="name_en">{{ $equipment->name_en }}</div>
            @endif

            <div style="text-align:center">
                @php
                    $statusMap = [
                        'ACTIVE' => ['b-active', 'ใช้งานอยู่'],
                        'BROKEN' => ['b-broken', 'ชำรุด'],
                        'UNDER_REPAIR' => ['b-repair', 'กำลังซ่อม'],
                        'RETIRED' => ['b-other', 'จำหน่ายแล้ว'],
                        'PENDING_DISPOSAL' => ['b-other', 'รอแทงจำหน่าย'],
                    ];
                    [$cls, $label] = $statusMap[$equipment->status] ?? ['b-other', $equipment->status];
                @endphp
                <span class="badge {{ $cls }}">{{ $label }}</span>
            </div>

            @php
                $calibrationLabels = [
                    'NONE'     => 'ไม่ต้องสอบเทียบ',
                    'INTERNAL' => 'ภายใน',
                    'EXTERNAL' => 'ภายนอก',
                    'BOTH'     => 'ทั้งภายในและภายนอก',
                ];
                $thMonths = [1=>'ม.ค.',2=>'ก.พ.',3=>'มี.ค.',4=>'เม.ย.',5=>'พ.ค.',6=>'มิ.ย.',7=>'ก.ค.',8=>'ส.ค.',9=>'ก.ย.',10=>'ต.ค.',11=>'พ.ย.',12=>'ธ.ค.'];
                $fmtThai = fn ($d) => $d ? ($d->day.' '.$thMonths[$d->month].' '.($d->year + 543)) : '—';
            @endphp

            <div class="field-grid">
                <div class="field-label">หน่วยงาน</div>
                <div class="field-value">
                    {{ $equipment->department?->name_th ?? '—' }}@if ($equipment->department?->code) <span style="color:#94a3b8">({{ $equipment->department->code }})</span>@endif
                </div>

                <div class="field-label">ยี่ห้อ</div>
                <div class="field-value">{{ $equipment->manufacturer ?: '—' }}</div>

                <div class="field-label">รุ่น</div>
                <div class="field-value">{{ $equipment->model ?: '—' }}</div>

                <div class="field-label">Serial Number</div>
                <div class="field-value">{{ $equipment->serial_number ?: '—' }}</div>

                <div class="field-label">หมายเลขครุภัณฑ์</div>
                <div class="field-value">{{ $equipment->asset_number ?: '—' }}</div>

                <div class="field-label">ปีงบประมาณ</div>
                <div class="field-value">{{ $equipment->fiscal_year ? 'พ.ศ. '.$equipment->fiscal_year : '—' }}</div>

                <div class="field-label">สอบเทียบโดย</div>
                <div class="field-value">{{ $calibrationLabels[$equipment->calibration_by] ?? ($equipment->calibration_by ?: '—') }}</div>

                <div class="field-label">รอบซ่อมบำรุง/ปี</div>
                <div class="field-value">{{ $equipment->maintenance_cycles_per_year !== null ? $equipment->maintenance_cycles_per_year.' ครั้ง/ปี' : '—' }}</div>

                <div class="field-label">วันที่ซื้อ</div>
                <div class="field-value">{{ $fmtThai($equipment->purchase_date) }}</div>

                @if ($equipment->latestCalibration)
                    <div class="field-label">สอบเทียบล่าสุด</div>
                    <div class="field-value">
                        {{ $fmtThai($equipment->latestCalibration->calibrated_at) }}
                        @if ($equipment->latestCalibration->result === 'PASS')
                            <span style="color:#059669">· ผ่าน</span>
                        @else
                            <span style="color:#dc2626">· ไม่ผ่าน</span>
                        @endif
                    </div>
                    @if ($equipment->latestCalibration->next_due_at)
                        <div class="field-label">ครบกำหนดสอบเทียบ</div>
                        <div class="field-value">{{ $fmtThai($equipment->latestCalibration->next_due_at) }}</div>
                    @endif
                @endif

                @if ($equipment->note)
                    <div class="field-label">หมายเหตุ</div>
                    <div class="field-value">{{ $equipment->note }}</div>
                @endif
            </div>
        </div>

        <div class="footer">
            สแกน QR Code นี้เพื่อดูข้อมูลเครื่องมือแพทย์<br />
            CK-MEMS · Medical Equipment Management System
        </div>
    </div>
</body>
</html>
