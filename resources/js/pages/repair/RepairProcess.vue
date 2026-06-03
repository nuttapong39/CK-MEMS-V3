<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    ArrowLeftIcon, ClockIcon, ExclamationTriangleIcon,
    UserCircleIcon, MapPinIcon, CalendarDaysIcon, WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline';
import Swal from 'sweetalert2';
import { repairsApi } from '../../api/repairs';
import { useAuthStore } from '../../stores/auth';
import StatusBadge from '../../components/repair/StatusBadge.vue';
import { URGENCY_META, STATUS_META } from '../../composables/repairStatus';

const route  = useRoute();
const router = useRouter();
const auth   = useAuthStore();

const ticket  = ref(null);
const loading = ref(true);
const saving  = ref(false);

// inline repair form state (for IN_PROGRESS stage)
const repairNote = ref('');

// ─── Helpers ─────────────────────────────────────────────────────────────────
function fmtDate(v) {
    if (!v) return '—';
    return new Date(v).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' });
}

async function load() {
    loading.value = true;
    try {
        const { data } = await repairsApi.show(route.params.id);
        // JsonResource wraps in {data: {...}} — unwrap it
        const t = data.data ?? data;
        ticket.value = t;
        repairNote.value = t.action_taken ?? '';
    } finally {
        loading.value = false;
    }
}
onMounted(load);

// ─── Computed ────────────────────────────────────────────────────────────────
const slaBadge = computed(() => {
    if (!ticket.value?.sla_due_at) return null;
    const due   = new Date(ticket.value.sla_due_at);
    const diffH = Math.round((due - Date.now()) / 36e5);
    if (ticket.value.sla_overdue)
        return { text: `เกิน SLA ${Math.abs(diffH)} ชม.`, cls: 'bg-red-100 text-red-700 border-red-200' };
    if (diffH < 4)
        return { text: `ครบ SLA ใน ${diffH} ชม.`, cls: 'bg-amber-100 text-amber-700 border-amber-200' };
    return {
        text: `SLA: ${due.toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' })}`,
        cls: 'bg-slate-100 text-slate-600 border-slate-200',
    };
});

const isClosed      = computed(() => ['CLOSED', 'CANCELLED'].includes(ticket.value?.status));
const isPending     = computed(() => ticket.value?.status === 'PENDING');
const isAcknowledged = computed(() => ticket.value?.status === 'ACKNOWLEDGED');
const isInProgress  = computed(() => ticket.value?.status === 'IN_PROGRESS');
const isWaiting     = computed(() => ticket.value?.status === 'WAITING_PARTS');
const isOutsourced  = computed(() => ticket.value?.status === 'OUTSOURCED');
const isRepaired    = computed(() => ticket.value?.status === 'REPAIRED');
const isVerified    = computed(() => ticket.value?.status === 'VERIFIED');

// ─── Transition wrapper ───────────────────────────────────────────────────────
async function doTransition(toStatus, payload = {}) {
    saving.value = true;
    try {
        const { data } = await repairsApi.transition(ticket.value.id, { to_status: toStatus, ...payload });
        const t = data.data ?? data;
        ticket.value = t;
        repairNote.value = t.action_taken ?? '';
    } catch (e) {
        await Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: e.response?.data?.message ?? 'เกิดข้อผิดพลาด' });
    } finally {
        saving.value = false;
    }
}

// ─── Stage actions ────────────────────────────────────────────────────────────

// 1. รับเรื่อง
async function acceptTicket() {
    const { isConfirmed } = await Swal.fire({
        icon: 'question',
        title: '📋 รับเรื่องแจ้งซ่อม',
        html: `<p style="color:#475569">ยืนยันรับ ticket <strong>${ticket.value.ticket_no}</strong><br>เครื่อง: <strong>${ticket.value.equipment?.name_th}</strong></p>`,
        showCancelButton: true,
        confirmButtonText: '✅ ยืนยันรับเรื่อง',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#2563eb',
    });
    if (!isConfirmed) return;
    await doTransition('ACKNOWLEDGED');
    await Swal.fire({ icon: 'success', title: 'รับเรื่องแล้ว!', text: 'สถานะเปลี่ยนเป็น "รับเรื่องแล้ว" เรียบร้อย', timer: 1800, showConfirmButton: false });
}

// 2. เริ่มดำเนินการ (ACKNOWLEDGED → IN_PROGRESS)
async function startRepair() {
    const { isConfirmed } = await Swal.fire({
        icon: 'info',
        title: '🔧 เริ่มดำเนินการซ่อม',
        text: 'ยืนยันเริ่มดำเนินการตรวจสอบและซ่อมเครื่องนี้',
        showCancelButton: true,
        confirmButtonText: 'เริ่มดำเนินการ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#d97706',
    });
    if (!isConfirmed) return;
    await doTransition('IN_PROGRESS');
}

// 3. ซ่อมเสร็จ
async function markRepaired() {
    if (!repairNote.value.trim()) {
        await Swal.fire({ icon: 'warning', title: 'กรุณาระบุรายละเอียดการซ่อม', text: 'โปรดกรอกว่าดำเนินการซ่อมอะไรไปบ้าง' });
        return;
    }
    const { value: fd } = await Swal.fire({
        title: '✅ ยืนยันซ่อมเสร็จ',
        html: `
            <div style="text-align:left;font-size:13px;color:#64748b;margin-bottom:4px;">สาเหตุของความเสีย</div>
            <textarea id="sw_root" class="swal2-textarea" style="height:60px" placeholder="ระบุสาเหตุ..."></textarea>
            <div style="display:flex;gap:10px">
                <input id="sw_parts" class="swal2-input" placeholder="อะไหล่ที่ใช้" />
                <input id="sw_cost" class="swal2-input" type="number" min="0" placeholder="ค่าซ่อม (บาท)" />
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'บันทึกผลซ่อม',
        cancelButtonText: 'ย้อนกลับ',
        confirmButtonColor: '#059669',
        preConfirm: () => ({
            root_cause:   document.getElementById('sw_root').value || null,
            parts_used:   document.getElementById('sw_parts').value || null,
            repair_cost:  document.getElementById('sw_cost').value || null,
        }),
    });
    if (!fd) return;
    await doTransition('REPAIRED', { action_taken: repairNote.value, ...fd });
    await Swal.fire({ icon: 'success', title: 'ซ่อมเสร็จแล้ว! 🎉', text: 'สถานะเปลี่ยนเป็น "ซ่อมเสร็จ" เรียบร้อย', timer: 2000, showConfirmButton: false });
}

// 4. รออะไหล่
async function waitParts() {
    const { value: note, isConfirmed } = await Swal.fire({
        title: '📦 รออะไหล่',
        input: 'textarea',
        inputLabel: 'ระบุอะไหล่ที่ต้องการ',
        inputPlaceholder: 'รายการอะไหล่ที่ต้องจัดหา...',
        showCancelButton: true,
        confirmButtonText: 'บันทึก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#7c3aed',
    });
    if (!isConfirmed) return;
    await doTransition('WAITING_PARTS', { note: note || null });
    await Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 1200, showConfirmButton: false });
}

// 5. ส่งซ่อมภายนอก
async function sendOutsource() {
    // Auto-generate outsource ref from backend before opening modal
    let autoRef = '';
    try {
        const { data } = await repairsApi.nextOutsourceRef();
        autoRef = data.ref ?? '';
    } catch { /* non-critical */ }

    const { value: fd } = await Swal.fire({
        title: '🚚 ส่งซ่อมภายนอก',
        width: '560px',
        html: `
            <div style="text-align:left;padding:0 4px">
                <label style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;">
                    ชื่อบริษัท / ผู้รับจ้างซ่อม <span style="color:#dc2626">*</span>
                </label>
                <input id="sw_vendor" class="swal2-input"
                    placeholder="ระบุชื่อบริษัทหรือผู้รับจ้าง..."
                    style="margin:0 0 14px;width:100%;box-sizing:border-box" />

                <label style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;">
                    สาเหตุที่ไม่สามารถซ่อมเองได้
                </label>
                <textarea id="sw_reason" class="swal2-textarea"
                    placeholder="เหตุผลที่ต้องส่งซ่อมภายนอก..."
                    style="margin:0 0 14px;width:100%;box-sizing:border-box;height:72px;resize:none"></textarea>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
                    <div>
                        <label style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;">
                            เลขที่ใบส่งซ่อม
                        </label>
                        <input id="sw_ref" class="swal2-input"
                            placeholder="เลขอ้างอิง"
                            value="${autoRef}"
                            style="margin:0;width:100%;box-sizing:border-box" />
                    </div>
                    <div>
                        <label style="display:block;font-size:12px;font-weight:600;color:#475569;margin-bottom:6px;">
                            วันกำหนดรับคืน
                        </label>
                        <input id="sw_date" class="swal2-input" type="date"
                            style="margin:0;width:100%;box-sizing:border-box" />
                    </div>
                </div>
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: '🚚 ยืนยันส่งซ่อมภายนอก',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#0284c7',
        customClass: { htmlContainer: '!text-left !px-6' },
        preConfirm: () => {
            const v = document.getElementById('sw_vendor').value.trim();
            if (!v) { Swal.showValidationMessage('กรุณาระบุชื่อบริษัทผู้รับซ่อม'); return false; }
            return {
                vendor_name:        v,
                note:               document.getElementById('sw_reason').value || null,
                outsource_ref:      document.getElementById('sw_ref').value || null,
                expected_return_at: document.getElementById('sw_date').value || null,
            };
        },
    });
    if (!fd) return;
    await doTransition('OUTSOURCED', { action_taken: repairNote.value || null, ...fd });
    await Swal.fire({ icon: 'success', title: 'ส่งซ่อมภายนอกแล้ว', text: 'สถานะเปลี่ยนเป็น "ส่งซ่อมภายนอก" เรียบร้อย', timer: 2000, showConfirmButton: false });
}

// 6. ซ่อมไม่ได้ (decommission)
async function markUnrepairable() {
    const { value: reason } = await Swal.fire({
        icon: 'warning',
        title: '🚫 ซ่อมไม่ได้ – ใช้งานไม่ได้',
        html: `
            <p style="color:#64748b;font-size:14px;margin-bottom:8px">เครื่องมือนี้จะถูกระบุว่า <strong style="color:#dc2626">ใช้งานไม่ได้</strong> และ<br>จะไม่สามารถแจ้งซ่อมได้อีก</p>
            <div style="text-align:left;font-size:13px;color:#64748b;margin-bottom:4px;">สาเหตุที่ซ่อมไม่ได้ <span style="color:#dc2626">*</span></div>
            <textarea id="sw_reason" class="swal2-textarea" style="height:80px" placeholder="ระบุสาเหตุที่ซ่อมไม่ได้..."></textarea>
        `,
        showCancelButton: true,
        confirmButtonText: 'ยืนยัน ซ่อมไม่ได้',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
        focusConfirm: false,
        preConfirm: () => {
            const v = document.getElementById('sw_reason').value.trim();
            if (!v) { Swal.showValidationMessage('กรุณาระบุสาเหตุ'); return false; }
            return v;
        },
    });
    if (!reason) return;

    // Double confirm
    const { isConfirmed } = await Swal.fire({
        icon: 'error',
        title: 'ยืนยันอีกครั้ง',
        text: 'การดำเนินการนี้ไม่สามารถย้อนกลับได้',
        showCancelButton: true,
        confirmButtonText: 'ยืนยัน ดำเนินการต่อ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
    });
    if (!isConfirmed) return;

    await doTransition('CANCELLED', {
        note: reason,
        decommission: true,
        decommission_reason: reason,
    });
    await Swal.fire({ icon: 'info', title: 'บันทึกแล้ว', text: 'เครื่องมือถูกระบุว่าใช้งานไม่ได้', timer: 2000, showConfirmButton: false });
}

// 7. ตรวจรับเครื่อง (REPAIRED → VERIFIED)
async function verifyTicket() {
    const { isConfirmed } = await Swal.fire({
        icon: 'question',
        title: '☑️ ยืนยันรับเครื่องคืน',
        html: `<p style="color:#475569">ยืนยันว่าเครื่อง <strong>${ticket.value.equipment?.name_th}</strong><br>ใช้งานได้ปกติและรับเครื่องคืนแล้ว?</p>`,
        showCancelButton: true,
        confirmButtonText: 'ยืนยัน รับเครื่องแล้ว',
        cancelButtonText: 'ยังไม่รับ',
        confirmButtonColor: '#059669',
    });
    if (!isConfirmed) return;
    await doTransition('VERIFIED');
    await Swal.fire({ icon: 'success', title: 'ตรวจรับเรียบร้อย!', timer: 1500, showConfirmButton: false });
}

// 8. ปิดงาน (VERIFIED → CLOSED)
async function closeTicket() {
    const { isConfirmed } = await Swal.fire({
        icon: 'info',
        title: '📁 ปิดงานซ่อม',
        text: 'ยืนยันปิด ticket นี้?',
        showCancelButton: true,
        confirmButtonText: 'ปิดงาน',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#475569',
    });
    if (!isConfirmed) return;
    await doTransition('CLOSED');
}

// 9. ซ่อมเสร็จจาก OUTSOURCED
async function outsourcedRepaired() {
    const { value: fd } = await Swal.fire({
        title: '✅ รับเครื่องคืน – ซ่อมเสร็จ',
        html: `
            <div style="text-align:left;font-size:13px;color:#64748b;margin-bottom:4px;">รายละเอียดการซ่อม (บริษัทซ่อมอะไรไปบ้าง) <span style="color:#dc2626">*</span></div>
            <textarea id="sw_action" class="swal2-textarea" style="height:80px" placeholder="รายละเอียดสิ่งที่ดำเนินการซ่อม..."></textarea>
            <div style="display:flex;gap:10px;margin-top:8px">
                <input id="sw_cost" class="swal2-input" type="number" min="0" placeholder="ค่าซ่อม (บาท)" style="flex:1;margin-top:0" />
            </div>
        `,
        focusConfirm: false,
        showCancelButton: true,
        confirmButtonText: 'บันทึก – ซ่อมเสร็จ',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#059669',
        preConfirm: () => {
            const v = document.getElementById('sw_action').value.trim();
            if (!v) { Swal.showValidationMessage('กรุณาระบุรายละเอียดการซ่อม'); return false; }
            return { action_taken: v, repair_cost: document.getElementById('sw_cost').value || null };
        },
    });
    if (!fd) return;
    await doTransition('REPAIRED', fd);
    await Swal.fire({ icon: 'success', title: 'ซ่อมเสร็จแล้ว! 🎉', timer: 2000, showConfirmButton: false });
}

// 10. กลับมาซ่อม (WAITING_PARTS → IN_PROGRESS)
async function resumeRepair() {
    const { isConfirmed } = await Swal.fire({
        icon: 'info', title: '🔧 ได้อะไหล่แล้ว – กลับมาซ่อม',
        text: 'ยืนยันกลับมาดำเนินการซ่อมต่อ',
        showCancelButton: true, confirmButtonText: 'ดำเนินการต่อ', cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#d97706',
    });
    if (!isConfirmed) return;
    await doTransition('IN_PROGRESS');
}

// 11. ยกเลิก (general)
async function cancelTicket() {
    const { value: reason, isConfirmed } = await Swal.fire({
        icon: 'warning', title: '✖ ยกเลิก ticket',
        input: 'textarea', inputLabel: 'เหตุผล',
        inputPlaceholder: 'ระบุเหตุผลการยกเลิก...',
        showCancelButton: true, confirmButtonText: 'ยืนยันยกเลิก', cancelButtonText: 'ย้อนกลับ',
        confirmButtonColor: '#dc2626',
    });
    if (!isConfirmed) return;
    await doTransition('CANCELLED', { note: reason || null });
    await Swal.fire({ icon: 'info', title: 'ยกเลิกแล้ว', timer: 1200, showConfirmButton: false });
}
</script>

<template>
    <div class="max-w-4xl mx-auto space-y-5">

        <!-- ─── Header ─────────────────────────────────────────────────── -->
        <div class="flex items-start gap-3">
            <button @click="router.push({ name: 'repair.list' })"
                class="mt-1 p-2 rounded-xl hover:bg-white hover:shadow-sm transition shrink-0">
                <ArrowLeftIcon class="w-5 h-5 text-slate-600" />
            </button>
            <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-bold text-slate-800">{{ ticket?.ticket_no ?? '...' }}</h1>
                    <StatusBadge v-if="ticket" :status="ticket.status" />
                    <span v-if="slaBadge"
                        :class="['inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-full font-medium border', slaBadge.cls]">
                        <ExclamationTriangleIcon v-if="ticket?.sla_overdue" class="w-3 h-3" />
                        <ClockIcon v-else class="w-3 h-3" />
                        {{ slaBadge.text }}
                    </span>
                </div>
                <p v-if="ticket" class="text-sm text-slate-500 mt-0.5">
                    <span class="font-medium text-slate-700">{{ ticket.equipment?.name_th }}</span>
                    <span class="mx-1.5 text-slate-300">·</span>{{ ticket.equipment?.id_code }}
                    <span class="mx-1.5 text-slate-300">·</span>{{ ticket.equipment?.department?.name_th }}
                </p>
            </div>
            <RouterLink :to="{ name: 'repair.detail', params: { id: route.params.id } }"
                class="shrink-0 mt-1 text-xs text-slate-400 hover:text-blue-600 hover:underline">
                ดูรายละเอียด →
            </RouterLink>
        </div>

        <div v-if="loading" class="card-base p-12 text-center text-slate-400">กำลังโหลด...</div>

        <template v-else-if="ticket">

            <!-- ─── Process Pipeline ──────────────────────────────────── -->
            <div class="card-base p-5">
                <div class="flex items-center justify-between text-xs text-slate-500 mb-2 font-medium">
                    <span>ขั้นตอนการดำเนินงาน</span>
                    <span v-if="ticket.assignee" class="text-blue-600">ช่าง: {{ ticket.assignee.full_name }}</span>
                </div>
                <!-- Step dots -->
                <div class="flex items-center">
                    <template v-for="(step, idx) in ['PENDING','ACKNOWLEDGED','IN_PROGRESS','REPAIRED','VERIFIED','CLOSED']" :key="step">
                        <div class="flex flex-col items-center flex-1 min-w-0">
                            <!-- dot with pulse for active step -->
                            <div class="relative">
                                <div :class="[
                                    'w-10 h-10 rounded-full flex items-center justify-center text-lg transition-all',
                                    ticket.status === step
                                        ? 'bg-blue-600 text-white shadow-lg shadow-blue-500/40'
                                        : ['CLOSED','REPAIRED','VERIFIED'].includes(ticket.status) && ['PENDING','ACKNOWLEDGED','IN_PROGRESS'].includes(step)
                                          ? 'bg-emerald-500 text-white'
                                          : ticket.status === 'CANCELLED'
                                            ? 'bg-gray-200 text-gray-400'
                                            : ['PENDING','ACKNOWLEDGED','IN_PROGRESS','REPAIRED','VERIFIED'].includes(ticket.status) &&
                                              ['PENDING','ACKNOWLEDGED','IN_PROGRESS','REPAIRED','VERIFIED','CLOSED'].indexOf(step) <
                                              ['PENDING','ACKNOWLEDGED','IN_PROGRESS','REPAIRED','VERIFIED','CLOSED'].indexOf(ticket.status)
                                              ? 'bg-emerald-500 text-white'
                                              : 'bg-slate-100 text-slate-400',
                                ]">
                                    {{ STATUS_META[step]?.icon }}
                                </div>
                                <!-- Pulse ring for current active step -->
                                <span v-if="ticket.status === step && !['CLOSED','CANCELLED'].includes(step)"
                                    class="absolute inset-0 rounded-full bg-blue-400 opacity-30 animate-ping"></span>
                            </div>
                            <div :class="['text-[10px] text-center mt-1.5 font-medium leading-tight px-0.5',
                                ticket.status === step ? 'text-blue-700' : 'text-slate-500']">
                                {{ STATUS_META[step]?.label }}
                            </div>
                        </div>
                        <div v-if="idx < 5" :class="['h-0.5 flex-1 -mx-1 mb-5 transition-colors',
                            (['PENDING','ACKNOWLEDGED','IN_PROGRESS','REPAIRED','VERIFIED','CLOSED'].indexOf(ticket.status) > idx)
                                && ticket.status !== 'CANCELLED'
                                ? 'bg-emerald-400' : 'bg-slate-200']">
                        </div>
                    </template>
                </div>
                <!-- Side-branch badge for WAITING_PARTS / OUTSOURCED -->
                <div v-if="['WAITING_PARTS','OUTSOURCED'].includes(ticket.status)"
                    class="mt-3 flex justify-center">
                    <span class="inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-semibold animate-pulse"
                        :class="ticket.status === 'OUTSOURCED' ? 'bg-sky-100 text-sky-700' : 'bg-violet-100 text-violet-700'">
                        {{ STATUS_META[ticket.status]?.icon }}
                        {{ STATUS_META[ticket.status]?.label }} (สถานะปัจจุบัน)
                    </span>
                </div>
                <div v-if="ticket.status === 'CANCELLED'" class="mt-3 text-center text-sm text-gray-500 font-medium">
                    ✖ ticket นี้ถูกยกเลิกแล้ว
                </div>
            </div>

            <!-- ═══════════════════════════════════════════════════════════
                 STAGE: PENDING — รอรับเรื่อง
            ════════════════════════════════════════════════════════════ -->
            <template v-if="isPending">
                <div class="card-base overflow-hidden">
                    <!-- Header bar -->
                    <div class="bg-rose-50 border-b border-rose-100 px-6 py-4 flex items-center gap-3">
                        <span class="text-2xl">⏳</span>
                        <div>
                            <div class="font-bold text-rose-700">รอรับเรื่อง</div>
                            <div class="text-xs text-rose-500">ticket ใหม่ รอการยืนยันจากเจ้าหน้าที่</div>
                        </div>
                    </div>
                    <!-- Ticket info -->
                    <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-6">
                        <div class="space-y-3">
                            <div class="text-xs uppercase text-slate-400 font-semibold tracking-wider">เครื่องมือที่แจ้งซ่อม</div>
                            <div>
                                <div class="font-mono text-blue-700 text-sm">{{ ticket.equipment?.id_code }}</div>
                                <div class="font-semibold text-slate-800 mt-0.5">{{ ticket.equipment?.name_th }}</div>
                                <div class="text-xs text-slate-500">{{ ticket.equipment?.manufacturer }} {{ ticket.equipment?.model }}</div>
                                <div class="text-xs text-slate-500 mt-0.5">หน่วยงาน: {{ ticket.equipment?.department?.name_th }}</div>
                            </div>
                        </div>
                        <div class="space-y-3">
                            <div class="text-xs uppercase text-slate-400 font-semibold tracking-wider">ผู้แจ้งซ่อม</div>
                            <div class="flex items-start gap-2">
                                <UserCircleIcon class="w-4 h-4 mt-0.5 text-slate-400" />
                                <div>
                                    <div class="font-medium text-sm">{{ ticket.reporter?.full_name ?? (ticket.reporter_name ? ticket.reporter_name + ' (QR)' : '—') }}</div>
                                    <div class="text-xs text-slate-500">{{ ticket.reporter?.department }}</div>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <CalendarDaysIcon class="w-4 h-4 text-slate-400" />
                                <div class="text-sm">{{ fmtDate(ticket.reported_at) }}</div>
                            </div>
                            <div>
                                <span :class="['inline-flex items-center gap-1.5 text-xs px-2.5 py-1 rounded-full font-medium', URGENCY_META[ticket.urgency]?.bg]">
                                    {{ URGENCY_META[ticket.urgency]?.label }} · {{ URGENCY_META[ticket.urgency]?.sla }}
                                </span>
                            </div>
                        </div>
                        <div class="sm:col-span-2">
                            <div class="text-xs uppercase text-slate-400 font-semibold tracking-wider mb-2">อาการที่แจ้ง</div>
                            <div class="bg-slate-50 rounded-xl p-4 text-sm text-slate-700 whitespace-pre-line leading-relaxed border border-slate-100">
                                {{ ticket.symptom }}
                            </div>
                        </div>
                    </div>
                    <!-- Accept button -->
                    <div class="px-6 pb-6">
                        <button
                            :disabled="saving"
                            @click="acceptTicket"
                            class="w-full flex items-center justify-center gap-3 py-4 rounded-2xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-lg shadow-lg shadow-blue-500/30 transition disabled:opacity-50"
                        >
                            <span class="text-2xl">📋</span>
                            รับเรื่องแจ้งซ่อม
                        </button>
                    </div>
                </div>
            </template>

            <!-- ═══════════════════════════════════════════════════════════
                 STAGE: ACKNOWLEDGED — รับเรื่องแล้ว รอดำเนินการ
            ════════════════════════════════════════════════════════════ -->
            <template v-else-if="isAcknowledged">
                <div class="card-base overflow-hidden">
                    <div class="bg-orange-50 border-b border-orange-100 px-6 py-4 flex items-center gap-3">
                        <span class="text-2xl">📋</span>
                        <div>
                            <div class="font-bold text-orange-700">รับเรื่องแล้ว</div>
                            <div class="text-xs text-orange-500">พร้อมดำเนินการตรวจสอบและซ่อม</div>
                        </div>
                    </div>
                    <div class="p-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div class="bg-slate-50 rounded-xl p-4 space-y-1.5 text-sm">
                            <div class="font-mono text-blue-700 text-xs">{{ ticket.equipment?.id_code }}</div>
                            <div class="font-semibold">{{ ticket.equipment?.name_th }}</div>
                            <div class="text-xs text-slate-500">{{ ticket.equipment?.department?.name_th }}</div>
                        </div>
                        <div class="bg-slate-50 rounded-xl p-4 text-sm">
                            <div class="text-xs text-slate-400 mb-1">อาการ</div>
                            <div class="text-slate-700 whitespace-pre-line text-sm">{{ ticket.symptom }}</div>
                        </div>
                    </div>
                    <div class="px-6 pb-6 flex gap-3">
                        <button
                            :disabled="saving"
                            @click="startRepair"
                            class="flex-1 flex items-center justify-center gap-2 py-3.5 rounded-2xl bg-amber-500 hover:bg-amber-600 text-white font-bold shadow-lg shadow-amber-400/30 transition disabled:opacity-50"
                        >
                            <WrenchScrewdriverIcon class="w-5 h-5" />
                            🔍 เริ่มตรวจสอบ / ดำเนินการซ่อม
                        </button>
                        <button @click="cancelTicket" :disabled="saving"
                            class="px-4 py-3.5 rounded-2xl border border-red-200 text-red-600 hover:bg-red-50 text-sm font-medium transition disabled:opacity-50">
                            ยกเลิก
                        </button>
                    </div>
                </div>
            </template>

            <!-- ═══════════════════════════════════════════════════════════
                 STAGE: IN_PROGRESS — กำลังซ่อม (main repair form)
            ════════════════════════════════════════════════════════════ -->
            <template v-else-if="isInProgress">
                <div class="card-base overflow-hidden">
                    <!-- Glowing header -->
                    <div class="bg-gradient-to-r from-amber-50 to-yellow-50 border-b border-amber-100 px-6 py-4 flex items-center gap-3">
                        <div class="relative">
                            <span class="text-3xl">🔧</span>
                            <span class="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-amber-400 animate-ping"></span>
                        </div>
                        <div>
                            <div class="font-bold text-amber-700 text-lg">กำลังดำเนินการซ่อม</div>
                            <div class="text-xs text-amber-600">
                                เริ่มซ่อมเมื่อ {{ fmtDate(ticket.started_at) }}
                                <span v-if="ticket.assignee" class="ml-2">· ช่าง: {{ ticket.assignee.full_name }}</span>
                            </div>
                        </div>
                    </div>

                    <div class="p-6 space-y-5">

                        <!-- Original complaint (read-only) -->
                        <div class="bg-slate-50 rounded-2xl p-4 border border-slate-100">
                            <div class="text-xs uppercase text-slate-400 font-semibold tracking-wider mb-3">ข้อมูลจากการแจ้งซ่อม</div>
                            <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm">
                                <div>
                                    <div class="text-[11px] text-slate-400">เครื่องมือ</div>
                                    <div class="font-mono text-blue-700 text-xs">{{ ticket.equipment?.id_code }}</div>
                                    <div class="font-medium">{{ ticket.equipment?.name_th }}</div>
                                </div>
                                <div>
                                    <div class="text-[11px] text-slate-400">ผู้แจ้ง / หน่วยงาน</div>
                                    <div>{{ ticket.reporter?.full_name ?? (ticket.reporter_name ? ticket.reporter_name + ' (QR)' : '—') }}</div>
                                    <div class="text-xs text-slate-500">{{ ticket.reporter?.department }}</div>
                                </div>
                                <div>
                                    <div class="text-[11px] text-slate-400">ความเร่งด่วน</div>
                                    <span :class="['inline-flex items-center gap-1 text-xs px-2 py-0.5 rounded-full font-medium mt-0.5', URGENCY_META[ticket.urgency]?.bg]">
                                        {{ URGENCY_META[ticket.urgency]?.label }}
                                    </span>
                                </div>
                                <div class="sm:col-span-3">
                                    <div class="text-[11px] text-slate-400 mb-1">อาการที่แจ้ง</div>
                                    <div class="text-slate-700 whitespace-pre-line text-sm leading-relaxed">{{ ticket.symptom }}</div>
                                </div>
                            </div>
                        </div>

                        <!-- ★ Repair notes (required) -->
                        <div>
                            <label class="flex items-center gap-1.5 text-sm font-semibold text-slate-800 mb-2">
                                <WrenchScrewdriverIcon class="w-4 h-4 text-amber-600" />
                                รายละเอียดการซ่อม
                                <span class="text-red-500">*</span>
                                <span class="text-xs font-normal text-slate-400">(บันทึกว่าดำเนินการอะไรไปบ้าง)</span>
                            </label>
                            <textarea
                                v-model="repairNote"
                                rows="5"
                                placeholder="กรอกรายละเอียดการซ่อม เช่น&#10;- ตรวจสอบพบว่า...&#10;- ดำเนินการเปลี่ยน...&#10;- ทดสอบแล้วพบว่า..."
                                class="w-full px-4 py-3 rounded-xl border border-slate-200 focus:border-amber-400 focus:ring-2 focus:ring-amber-100 outline-none transition text-sm leading-relaxed resize-none"
                            ></textarea>
                        </div>

                        <!-- Primary action: ซ่อมเสร็จ -->
                        <button
                            :disabled="saving"
                            @click="markRepaired"
                            class="w-full flex items-center justify-center gap-3 py-4 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-lg shadow-lg shadow-emerald-500/30 transition disabled:opacity-50"
                        >
                            <span class="text-2xl">✅</span>
                            ซ่อมเสร็จแล้ว – บันทึกผล
                        </button>

                        <!-- Divider: หากซ่อมไม่ได้ -->
                        <div class="border-t border-dashed border-slate-200 pt-4">
                            <div class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">
                                หากซ่อมไม่ได้ / ต้องดำเนินการต่อ
                            </div>
                            <div class="flex flex-col sm:flex-row gap-3">
                                <!-- รออะไหล่ -->
                                <button :disabled="saving" @click="waitParts"
                                    class="flex-1 flex items-center justify-center gap-2 py-3 rounded-xl bg-violet-100 hover:bg-violet-200 text-violet-700 font-semibold transition disabled:opacity-50">
                                    <span>📦</span> รออะไหล่
                                </button>
                                <!-- ส่งซ่อมภายนอก -->
                                <button :disabled="saving" @click="sendOutsource"
                                    class="flex-1 flex items-center justify-center gap-2 py-3 rounded-xl bg-sky-100 hover:bg-sky-200 text-sky-700 font-semibold transition disabled:opacity-50">
                                    <span>🚚</span> ส่งซ่อมภายนอก
                                </button>
                                <!-- ซ่อมไม่ได้ -->
                                <button :disabled="saving" @click="markUnrepairable"
                                    class="flex-1 flex items-center justify-center gap-2 py-3 rounded-xl bg-red-100 hover:bg-red-200 text-red-700 font-semibold transition disabled:opacity-50">
                                    <span>🚫</span> ซ่อมไม่ได้
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </template>

            <!-- ═══════════════════════════════════════════════════════════
                 STAGE: WAITING_PARTS
            ════════════════════════════════════════════════════════════ -->
            <template v-else-if="isWaiting">
                <div class="card-base p-6 text-center space-y-4">
                    <div class="text-5xl animate-bounce">📦</div>
                    <div class="font-bold text-violet-700 text-lg">รออะไหล่</div>
                    <div class="text-sm text-slate-500">รอการจัดหาอะไหล่ที่จำเป็น</div>
                    <div class="flex gap-3 justify-center pt-2">
                        <button :disabled="saving" @click="resumeRepair"
                            class="px-8 py-3 rounded-xl bg-amber-500 hover:bg-amber-600 text-white font-semibold shadow transition disabled:opacity-50">
                            🔧 ได้อะไหล่แล้ว – กลับมาซ่อม
                        </button>
                        <button :disabled="saving" @click="cancelTicket"
                            class="px-5 py-3 rounded-xl border border-red-200 text-red-600 hover:bg-red-50 text-sm transition disabled:opacity-50">
                            ยกเลิก
                        </button>
                    </div>
                </div>
            </template>

            <!-- ═══════════════════════════════════════════════════════════
                 STAGE: OUTSOURCED
            ════════════════════════════════════════════════════════════ -->
            <template v-else-if="isOutsourced">
                <div class="card-base overflow-hidden">
                    <div class="bg-sky-50 border-b border-sky-100 px-6 py-4 flex items-center gap-3">
                        <span class="text-2xl">🚚</span>
                        <div>
                            <div class="font-bold text-sky-700">ส่งซ่อมภายนอก</div>
                            <div class="text-xs text-sky-600">รอรับเครื่องคืนจากบริษัทผู้รับซ่อม</div>
                        </div>
                    </div>
                    <div class="p-6">
                        <dl class="grid grid-cols-2 gap-4 text-sm mb-6">
                            <div v-if="ticket.vendor_name">
                                <dt class="text-xs text-slate-400">บริษัทผู้รับซ่อม</dt>
                                <dd class="font-semibold">{{ ticket.vendor_name }}</dd>
                            </div>
                            <div v-if="ticket.outsource_ref">
                                <dt class="text-xs text-slate-400">เลขที่ใบส่งซ่อม</dt>
                                <dd class="font-mono">{{ ticket.outsource_ref }}</dd>
                            </div>
                            <div v-if="ticket.outsourced_at">
                                <dt class="text-xs text-slate-400">วันที่ส่ง</dt>
                                <dd>{{ fmtDate(ticket.outsourced_at) }}</dd>
                            </div>
                            <div v-if="ticket.expected_return_at">
                                <dt class="text-xs text-slate-400">วันคาดรับคืน</dt>
                                <dd>{{ fmtDate(ticket.expected_return_at) }}</dd>
                            </div>
                        </dl>
                        <button :disabled="saving" @click="outsourcedRepaired"
                            class="w-full flex items-center justify-center gap-3 py-4 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-lg shadow-lg shadow-emerald-500/20 transition disabled:opacity-50">
                            <span>✅</span> รับเครื่องคืน – ซ่อมเสร็จแล้ว
                        </button>
                        <button @click="cancelTicket" :disabled="saving"
                            class="w-full mt-2 py-2.5 rounded-xl border border-red-200 text-red-600 hover:bg-red-50 text-sm transition disabled:opacity-50">
                            ยกเลิก ticket
                        </button>
                    </div>
                </div>
            </template>

            <!-- ═══════════════════════════════════════════════════════════
                 STAGE: REPAIRED — รอตรวจรับ
            ════════════════════════════════════════════════════════════ -->
            <template v-else-if="isRepaired">
                <div class="card-base p-6 space-y-4">
                    <div class="text-center space-y-2">
                        <div class="text-5xl">✅</div>
                        <div class="font-bold text-emerald-700 text-xl">ซ่อมเสร็จแล้ว</div>
                        <div class="text-sm text-slate-500">รอผู้แจ้งตรวจรับเครื่อง</div>
                    </div>
                    <div v-if="ticket.action_taken" class="bg-emerald-50 rounded-xl p-4 text-sm border border-emerald-100">
                        <div class="text-xs text-emerald-600 font-semibold mb-1">สิ่งที่ดำเนินการซ่อม</div>
                        <div class="whitespace-pre-line">{{ ticket.action_taken }}</div>
                    </div>
                    <div class="flex gap-3">
                        <button :disabled="saving" @click="verifyTicket"
                            class="flex-1 py-3.5 rounded-2xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold shadow transition disabled:opacity-50">
                            ☑️ ตรวจรับเครื่อง
                        </button>
                        <button :disabled="saving" @click="doTransition('IN_PROGRESS')"
                            class="px-5 py-3.5 rounded-2xl border border-amber-200 text-amber-700 hover:bg-amber-50 text-sm font-medium transition disabled:opacity-50">
                            🔧 ยังมีปัญหา
                        </button>
                    </div>
                </div>
            </template>

            <!-- ═══════════════════════════════════════════════════════════
                 STAGE: VERIFIED — ตรวจรับแล้ว
            ════════════════════════════════════════════════════════════ -->
            <template v-else-if="isVerified">
                <div class="card-base p-8 text-center space-y-4">
                    <div class="text-5xl">☑️</div>
                    <div class="font-bold text-teal-700 text-xl">ตรวจรับเรียบร้อย</div>
                    <div class="text-sm text-slate-500">ผู้แจ้งยืนยันรับเครื่องคืนแล้ว เหลือเพียงปิดงาน</div>
                    <button :disabled="saving" @click="closeTicket"
                        class="mx-auto block px-10 py-3.5 rounded-2xl bg-slate-600 hover:bg-slate-700 text-white font-bold shadow transition disabled:opacity-50">
                        📁 ปิดงาน
                    </button>
                </div>
            </template>

            <!-- ═══════════════════════════════════════════════════════════
                 STAGE: CLOSED / CANCELLED
            ════════════════════════════════════════════════════════════ -->
            <template v-else-if="isClosed">
                <div :class="['card-base p-10 text-center space-y-3', ticket.status === 'CANCELLED' ? 'bg-gray-50' : 'bg-emerald-50']">
                    <div class="text-5xl">{{ STATUS_META[ticket.status]?.icon }}</div>
                    <div :class="['font-bold text-xl', ticket.status === 'CANCELLED' ? 'text-gray-600' : 'text-emerald-700']">
                        {{ STATUS_META[ticket.status]?.label }}
                    </div>
                    <div class="text-sm text-slate-500">
                        {{ ticket.status === 'CLOSED' ? `ปิดงานเมื่อ ${fmtDate(ticket.closed_at)}` : `ยกเลิกเมื่อ ${fmtDate(ticket.closed_at)}` }}
                    </div>
                </div>
            </template>

            <!-- Activity Log -->
            <div v-if="ticket.progress_logs?.length" class="card-base p-5">
                <div class="text-xs uppercase text-slate-400 font-semibold tracking-wider mb-4">บันทึกกิจกรรม</div>
                <ol class="relative pl-5 border-l-2 border-slate-100 space-y-4">
                    <li v-for="(log, i) in [...(ticket.progress_logs ?? [])].reverse()" :key="log.id ?? i" class="relative">
                        <span class="absolute -left-[26px] top-1.5 w-3 h-3 rounded-full bg-blue-500 ring-4 ring-blue-50"></span>
                        <div class="text-sm text-slate-700">
                            <span v-if="log.from_status" class="text-slate-400 text-xs">{{ STATUS_META[log.from_status]?.label }}</span>
                            <span v-else class="text-slate-400 text-xs">เริ่มต้น</span>
                            <span class="mx-1.5 text-slate-300">→</span>
                            <span class="font-semibold">{{ STATUS_META[log.to_status]?.label ?? log.to_status }}</span>
                        </div>
                        <div v-if="log.note" class="text-xs text-slate-500 mt-0.5">"{{ log.note }}"</div>
                        <div class="text-[11px] text-slate-400 mt-0.5">
                            {{ log.changed_by }} · {{ fmtDate(log.changed_at) }}
                        </div>
                    </li>
                </ol>
            </div>

        </template>
    </div>
</template>
