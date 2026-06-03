<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="csrf-token" content="{{ csrf_token() }}" />
    <title>แจ้งซ่อม — {{ $equipment->id_code }} {{ $equipment->name_th }}</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans+Thai:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --blue: #2563eb; --rose: #e11d48; --slate-50: #f8fafc; --slate-100: #f1f5f9; --slate-200: #e2e8f0; --slate-300: #cbd5e1; --slate-500: #64748b; --slate-700: #334155; --slate-800: #1e293b; }
        * { box-sizing: border-box; -webkit-font-smoothing: antialiased; }
        body {
            margin: 0; padding: 0;
            font-family: 'IBM Plex Sans Thai', 'Sarabun', system-ui, sans-serif;
            background: linear-gradient(135deg, #ffe4e6, #fff, #fff7ed);
            min-height: 100vh; color: var(--slate-800);
        }
        .container { max-width: 480px; margin: 0 auto; padding: 24px 16px; }
        .card {
            background: white; border-radius: 18px; padding: 24px;
            box-shadow: 0 12px 32px rgba(15, 23, 42, 0.08);
            border: 1px solid var(--slate-100);
        }
        .header { text-align: center; padding-bottom: 16px; border-bottom: 1px solid var(--slate-100); }
        .logo {
            width: 48px; height: 48px;
            background: linear-gradient(135deg, #f43f5e, #f97316);
            color: white; font-weight: 700; font-size: 20px;
            border-radius: 14px; display: inline-flex;
            align-items: center; justify-content: center; margin-bottom: 8px;
        }
        h1 { font-size: 16px; margin: 0; color: var(--rose); font-weight: 700; }
        .subtitle { font-size: 11px; color: var(--slate-500); margin-top: 2px; }

        .equip-box {
            margin: 16px 0; padding: 14px;
            background: linear-gradient(135deg, #fff1f2, #fff7ed);
            border-radius: 12px; border: 1px solid #fecdd3;
        }
        .equip-id { font-family: 'JetBrains Mono', monospace; font-size: 16px; font-weight: 700; color: var(--rose); }
        .equip-name { font-size: 15px; font-weight: 600; margin-top: 2px; }
        .equip-meta { font-size: 12px; color: var(--slate-500); margin-top: 4px; }

        label.field-label { display: block; font-size: 13px; font-weight: 600; color: var(--slate-700); margin: 16px 0 6px; }
        .req { color: var(--rose); }
        input[type="text"], input[type="datetime-local"], textarea {
            width: 100%; padding: 11px 12px; border-radius: 12px;
            border: 1px solid var(--slate-200); font-size: 14px;
            font-family: inherit; outline: none; transition: border-color .15s, box-shadow .15s;
            background: white; color: var(--slate-800);
        }
        input:focus, textarea:focus { border-color: var(--rose); box-shadow: 0 0 0 3px rgba(225,29,72,.1); }
        textarea { resize: vertical; min-height: 90px; }

        .urgency-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; }
        .urg-btn {
            display: flex; flex-direction: column; align-items: flex-start; gap: 2px;
            padding: 10px 12px; border-radius: 12px; border: 2px solid var(--slate-200);
            background: white; cursor: pointer; text-align: left; transition: all .15s;
            font-family: inherit;
        }
        .urg-btn .urg-top { display: flex; align-items: center; gap: 8px; }
        .urg-dot { width: 10px; height: 10px; border-radius: 999px; }
        .urg-name { font-size: 14px; font-weight: 600; color: var(--slate-800); }
        .urg-sla { font-size: 10px; color: var(--slate-500); }
        .urg-btn.active { border-color: transparent; color: white; box-shadow: 0 4px 12px rgba(0,0,0,.12); }
        .urg-btn.active .urg-name, .urg-btn.active .urg-sla { color: white; }
        .urg-btn.active .urg-dot { background: white !important; }

        .submit-btn {
            width: 100%; margin-top: 22px; padding: 13px;
            border: none; border-radius: 12px; cursor: pointer;
            background: linear-gradient(135deg, #f43f5e, #f97316);
            color: white; font-size: 15px; font-weight: 700; font-family: inherit;
            box-shadow: 0 8px 20px rgba(244,63,94,.3); transition: opacity .15s;
        }
        .submit-btn:disabled { opacity: .55; cursor: not-allowed; }
        .footer { text-align: center; font-size: 11px; color: var(--slate-500); margin-top: 16px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <div class="logo">🔧</div>
                <h1>แจ้งซ่อมเครื่องมือแพทย์</h1>
                <div class="subtitle">{{ $equipment->hospital->name_th ?? 'CK-MEMS' }}</div>
            </div>

            <div class="equip-box">
                <div class="equip-id">{{ $equipment->id_code }}</div>
                <div class="equip-name">{{ $equipment->name_th }}</div>
                <div class="equip-meta">
                    @if ($equipment->manufacturer || $equipment->model)
                        {{ trim($equipment->manufacturer.' '.$equipment->model) }} ·
                    @endif
                    {{ $equipment->location->name ?? $equipment->department->name_th ?? '' }}
                </div>
            </div>

            <form id="repair-form">
                <label class="field-label" for="reporter_name">ชื่อผู้แจ้ง <span style="color:var(--slate-500);font-weight:400">(ไม่บังคับ)</span></label>
                <input type="text" id="reporter_name" name="reporter_name" maxlength="191" placeholder="เช่น พยาบาลประจำหน่วยงาน" />

                <label class="field-label" for="reported_at">วันที่/เวลาที่แจ้ง <span class="req">*</span></label>
                <input type="datetime-local" id="reported_at" name="reported_at" required />

                <label class="field-label" for="symptom">อาการที่พบ <span class="req">*</span></label>
                <textarea id="symptom" name="symptom" required minlength="3"
                    placeholder="เช่น เครื่องไม่ติด ไฟแสดงสถานะกระพริบสีแดง..."></textarea>

                <label class="field-label">ระดับความเร่งด่วน <span class="req">*</span></label>
                <div class="urgency-grid" id="urgency-grid">
                    <button type="button" class="urg-btn" data-urgency="CRITICAL" data-bg="#dc2626" data-dot="#ef4444">
                        <span class="urg-top"><span class="urg-dot" style="background:#ef4444"></span><span class="urg-name">วิกฤติ</span></span>
                        <span class="urg-sla">ภายใน 1 ชม.</span>
                    </button>
                    <button type="button" class="urg-btn" data-urgency="HIGH" data-bg="#f97316" data-dot="#f97316">
                        <span class="urg-top"><span class="urg-dot" style="background:#f97316"></span><span class="urg-name">สูง</span></span>
                        <span class="urg-sla">ภายใน 4 ชม.</span>
                    </button>
                    <button type="button" class="urg-btn active" data-urgency="MEDIUM" data-bg="#fbbf24" data-dot="#fbbf24" style="border-color:transparent;background:#fbbf24">
                        <span class="urg-top"><span class="urg-dot" style="background:#fff"></span><span class="urg-name" style="color:#fff">ปานกลาง</span></span>
                        <span class="urg-sla" style="color:#fff">ภายใน 24 ชม.</span>
                    </button>
                    <button type="button" class="urg-btn" data-urgency="LOW" data-bg="#10b981" data-dot="#10b981">
                        <span class="urg-top"><span class="urg-dot" style="background:#10b981"></span><span class="urg-name">ต่ำ</span></span>
                        <span class="urg-sla">ภายใน 7 วัน</span>
                    </button>
                </div>

                <button type="submit" class="submit-btn" id="submit-btn">แจ้งซ่อม</button>
            </form>
        </div>

        <div class="footer">
            สแกน QR Code นี้เพื่อแจ้งซ่อมเครื่องมือแพทย์<br />
            CK-MEMS · Medical Equipment Management System
        </div>
    </div>

    <script>
        const postUrl = @json(url('/qr/repair/'.$equipment->id_code));
        const csrf = document.querySelector('meta[name="csrf-token"]').content;

        // Default reported_at = now (local), formatted for datetime-local
        (function () {
            const d = new Date();
            d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
            document.getElementById('reported_at').value = d.toISOString().slice(0, 16);
        })();

        // Urgency selector
        let urgency = 'MEDIUM';
        const grid = document.getElementById('urgency-grid');
        grid.addEventListener('click', (e) => {
            const btn = e.target.closest('.urg-btn');
            if (!btn) return;
            urgency = btn.dataset.urgency;
            grid.querySelectorAll('.urg-btn').forEach((b) => {
                const on = b === btn;
                b.classList.toggle('active', on);
                b.style.background = on ? b.dataset.bg : 'white';
                b.style.borderColor = on ? 'transparent' : 'var(--slate-200)';
                b.querySelector('.urg-name').style.color = on ? '#fff' : 'var(--slate-800)';
                b.querySelector('.urg-sla').style.color = on ? '#fff' : 'var(--slate-500)';
                b.querySelector('.urg-dot').style.background = on ? '#fff' : b.dataset.dot;
            });
        });

        // Submit
        const form = document.getElementById('repair-form');
        const submitBtn = document.getElementById('submit-btn');
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            submitBtn.disabled = true;
            submitBtn.textContent = 'กำลังบันทึก...';
            try {
                const res = await fetch(postUrl, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({
                        reporter_name: document.getElementById('reporter_name').value.trim(),
                        reported_at: document.getElementById('reported_at').value,
                        symptom: document.getElementById('symptom').value.trim(),
                        urgency: urgency,
                    }),
                });
                const data = await res.json().catch(() => ({}));

                if (res.ok) {
                    const result = await Swal.fire({
                        icon: 'success',
                        title: 'แจ้งซ่อมเรียบร้อย',
                        html: 'หมายเลข <b>' + (data.ticket_no || '') + '</b>',
                        showCancelButton: true,
                        confirmButtonText: 'ไปยังหน้า login',
                        cancelButtonText: 'ปิด',
                        confirmButtonColor: '#e11d48',
                        cancelButtonColor: '#64748b',
                        allowOutsideClick: false,
                        allowEscapeKey: false,
                    });
                    if (result.isConfirmed) {
                        window.location.href = '/login';
                    } else {
                        // ปิดหน้านี้ — พยายามปิด tab (ใช้ได้กับ in-app browser ของตัวสแกน QR)
                        window.open('', '_self');
                        window.close();
                        // fallback ถ้าเบราว์เซอร์ไม่ยอมปิด tab ที่ผู้ใช้เปิดเอง
                        document.documentElement.innerHTML =
                            '<body style="font-family:\'IBM Plex Sans Thai\',sans-serif;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0;background:linear-gradient(135deg,#ffe4e6,#fff,#fff7ed);text-align:center;color:#334155">'
                          + '<div><div style="font-size:48px">✅</div><h2 style="color:#e11d48;margin:8px 0">แจ้งซ่อมเรียบร้อยแล้ว</h2><p>ปิดหน้านี้ได้เลย ขอบคุณครับ</p></div></body>';
                    }
                    return;
                } else if (res.status === 422) {
                    const msg = data.message || (data.errors ? Object.values(data.errors)[0][0] : 'กรุณาตรวจสอบข้อมูล');
                    Swal.fire({ icon: 'warning', title: 'กรุณาตรวจสอบข้อมูล', text: msg, confirmButtonColor: '#e11d48' });
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'แจ้งซ่อม';
                } else {
                    throw new Error(data.message || 'เกิดข้อผิดพลาด');
                }
            } catch (err) {
                Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: String(err.message || err), confirmButtonColor: '#e11d48' });
                submitBtn.disabled = false;
                submitBtn.textContent = 'แจ้งซ่อม';
            }
        });
    </script>
</body>
</html>
