<script setup>
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeftIcon, ClockIcon, ExclamationTriangleIcon, Cog8ToothIcon } from '@heroicons/vue/24/outline';
import Swal from 'sweetalert2';
import { repairsApi } from '../../api/repairs';
import { useAuthStore } from '../../stores/auth';
import StatusBadge from '../../components/repair/StatusBadge.vue';
import ProcessTimeline from '../../components/repair/ProcessTimeline.vue';
import { URGENCY_META, STATUS_META } from '../../composables/repairStatus';

const route = useRoute();
const router = useRouter();
const auth = useAuthStore();

const ticket = ref(null);
const loading = ref(true);

const canManage = computed(() => auth.hasAnyRole(['admin', 'staff']));
const isReporter = computed(() => ticket.value && auth.user?.id === ticket.value.reporter?.id);

// VERIFIED can be done by the reporter or admin/staff
const canVerify = computed(() => isReporter.value || canManage.value);

// next_statuses filtered by what this user can trigger
const availableTransitions = computed(() => {
    if (!ticket.value?.next_statuses) return [];
    return ticket.value.next_statuses.filter((s) => {
        if (s === 'VERIFIED') return canVerify.value;
        return canManage.value;
    });
});

const slaBadge = computed(() => {
    if (!ticket.value?.sla_due_at) return null;
    const due = new Date(ticket.value.sla_due_at);
    const overdue = ticket.value.sla_overdue;
    const diffMs = due - Date.now();
    const diffH = Math.round(diffMs / 36e5);
    if (overdue) return { text: `เกิน SLA แล้ว ${Math.abs(diffH)} ชม.`, cls: 'bg-red-100 text-red-700' };
    if (diffH < 4) return { text: `ครบ SLA ใน ${diffH} ชม.`, cls: 'bg-amber-100 text-amber-700' };
    return { text: `SLA: ${due.toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' })}`, cls: 'bg-slate-100 text-slate-600' };
});

async function load() {
    loading.value = true;
    try {
        const { data } = await repairsApi.show(route.params.id);
        ticket.value = data;
    } finally {
        loading.value = false;
    }
}
onMounted(load);

async function transitionTo(toStatus) {
    const meta = STATUS_META[toStatus];
    let payload = { to_status: toStatus };

    if (toStatus === 'REPAIRED') {
        const { value: form } = await Swal.fire({
            title: 'บันทึกผลการซ่อม',
            html: `
                <textarea id="root_cause" class="swal2-textarea" placeholder="สาเหตุของความเสีย"></textarea>
                <textarea id="action_taken" class="swal2-textarea" placeholder="วิธีการแก้ไข"></textarea>
                <input id="parts_used" class="swal2-input" placeholder="อะไหล่ที่ใช้" />
                <input id="repair_cost" class="swal2-input" type="number" min="0" placeholder="ค่าใช้จ่าย (บาท)" />
            `,
            focusConfirm: false,
            showCancelButton: true,
            confirmButtonText: 'บันทึกผลซ่อม',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#0d9488',
            preConfirm: () => ({
                root_cause:  document.getElementById('root_cause').value || null,
                action_taken: document.getElementById('action_taken').value || null,
                parts_used:  document.getElementById('parts_used').value || null,
                repair_cost: document.getElementById('repair_cost').value || null,
            }),
        });
        if (!form) return;
        payload = { ...payload, ...form };

    } else if (toStatus === 'OUTSOURCED') {
        const { value: form } = await Swal.fire({
            title: 'ส่งซ่อมภายนอก',
            html: `
                <input id="vendor_name" class="swal2-input" placeholder="ชื่อบริษัท / ผู้รับจ้างซ่อม *" required />
                <input id="outsource_ref" class="swal2-input" placeholder="เลขที่ใบส่งซ่อม" />
                <label class="swal2-label" style="font-size:13px;color:#64748b;margin-top:8px;">วันคาดว่าจะได้รับคืน</label>
                <input id="expected_return_at" class="swal2-input" type="date" />
                <textarea id="note" class="swal2-textarea" placeholder="หมายเหตุ"></textarea>
            `,
            focusConfirm: false,
            showCancelButton: true,
            confirmButtonText: 'ยืนยันส่งซ่อม',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#0284c7',
            preConfirm: () => {
                const vendor = document.getElementById('vendor_name').value.trim();
                if (!vendor) { Swal.showValidationMessage('กรุณาระบุชื่อบริษัทผู้รับซ่อม'); return false; }
                return {
                    vendor_name:        vendor,
                    outsource_ref:      document.getElementById('outsource_ref').value || null,
                    expected_return_at: document.getElementById('expected_return_at').value || null,
                    note:               document.getElementById('note').value || null,
                };
            },
        });
        if (!form) return;
        payload = { ...payload, ...form };

    } else if (toStatus === 'VERIFIED') {
        const { isConfirmed } = await Swal.fire({
            title: 'ยืนยันรับเครื่องคืน',
            text: 'ท่านตรวจสอบแล้วว่าเครื่องใช้งานได้ปกติ และรับเครื่องคืนเรียบร้อยแล้ว?',
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'ยืนยัน รับเครื่องแล้ว',
            cancelButtonText: 'ยังไม่รับ',
            confirmButtonColor: '#059669',
        });
        if (!isConfirmed) return;

    } else {
        const { value: note, isConfirmed } = await Swal.fire({
            title: 'เปลี่ยนสถานะ → ' + meta.label,
            input: 'textarea',
            inputLabel: 'หมายเหตุ (ไม่บังคับ)',
            inputPlaceholder: 'รายละเอียดเพิ่มเติม...',
            showCancelButton: true,
            confirmButtonText: 'ยืนยัน',
            cancelButtonText: 'ยกเลิก',
            confirmButtonColor: '#2563eb',
        });
        if (!isConfirmed) return;
        payload.note = note || null;
    }

    try {
        const { data } = await repairsApi.transition(ticket.value.id, payload);
        ticket.value = data;
        Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 1200, showConfirmButton: false });
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: e.response?.data?.message || '' });
    }
}

function fmtDate(val) {
    if (!val) return '—';
    return new Date(val).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <div class="max-w-4xl mx-auto space-y-5">
        <!-- Header -->
        <div class="flex items-center gap-3">
            <button @click="router.back()" class="p-2 rounded-xl hover:bg-white hover:shadow-sm transition">
                <ArrowLeftIcon class="w-5 h-5 text-slate-600" />
            </button>
            <!-- Process management shortcut (admin/staff only) -->
        <RouterLink
            v-if="canManage && ticket && !['CLOSED','CANCELLED'].includes(ticket.status)"
            :to="{ name: 'repair.process', params: { id: route.params.id } }"
            class="shrink-0 inline-flex items-center gap-1.5 px-3 py-2 rounded-xl bg-blue-600 text-white text-sm font-medium hover:bg-blue-700 transition shadow"
        >
            <Cog8ToothIcon class="w-4 h-4" />
            จัดการซ่อม
        </RouterLink>
        <div class="flex-1 min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h1 class="text-xl font-bold text-slate-800">{{ ticket?.ticket_no || 'กำลังโหลด...' }}</h1>
                    <StatusBadge v-if="ticket" :status="ticket.status" />
                    <!-- SLA badge -->
                    <span v-if="slaBadge" :class="['inline-flex items-center gap-1 text-[11px] px-2 py-0.5 rounded-full font-medium', slaBadge.cls]">
                        <ExclamationTriangleIcon v-if="ticket.sla_overdue" class="w-3 h-3" />
                        <ClockIcon v-else class="w-3 h-3" />
                        {{ slaBadge.text }}
                    </span>
                </div>
                <p v-if="ticket" class="text-xs text-slate-500 mt-0.5">
                    แจ้งเมื่อ {{ fmtDate(ticket.reported_at) }}
                </p>
            </div>
        </div>

        <div v-if="loading" class="card-base p-12 text-center text-slate-400">กำลังโหลดรายละเอียด...</div>

        <template v-else-if="ticket">
            <!-- Workflow timeline -->
            <div class="card-base p-6">
                <ProcessTimeline :current-status="ticket.status" :logs="ticket.progress_logs || []" />
            </div>

            <!-- Detail cards -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div class="card-base p-5">
                    <div class="text-xs uppercase text-slate-400 tracking-wider mb-3 font-semibold">เครื่องมือ</div>
                    <div class="space-y-1.5">
                        <div class="text-sm font-mono text-blue-700">{{ ticket.equipment?.id_code }}</div>
                        <div class="font-medium text-slate-800">{{ ticket.equipment?.name_th }}</div>
                        <div class="text-xs text-slate-500">{{ ticket.equipment?.manufacturer }} · {{ ticket.equipment?.model }}</div>
                        <div class="text-xs text-slate-500">หน่วยงาน: {{ ticket.equipment?.department?.name_th }}</div>
                    </div>
                </div>

                <div class="card-base p-5">
                    <div class="text-xs uppercase text-slate-400 tracking-wider mb-3 font-semibold">รายละเอียดการแจ้ง</div>
                    <div class="space-y-2.5">
                        <div>
                            <div class="text-[10px] uppercase text-slate-400">ผู้แจ้ง</div>
                            <div class="text-sm">{{ ticket.reporter?.full_name ?? (ticket.reporter_name ? ticket.reporter_name + ' (แจ้งผ่าน QR)' : '—') }}</div>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase text-slate-400">ความเร่งด่วน</div>
                            <span :class="['inline-flex items-center gap-1 mt-1 text-xs px-2 py-1 rounded-full font-medium', URGENCY_META[ticket.urgency]?.bg]">
                                {{ URGENCY_META[ticket.urgency]?.label }} · {{ URGENCY_META[ticket.urgency]?.sla }}
                            </span>
                        </div>
                        <div>
                            <div class="text-[10px] uppercase text-slate-400">อาการ</div>
                            <div class="text-sm whitespace-pre-line">{{ ticket.symptom }}</div>
                        </div>
                        <div v-if="ticket.assignee">
                            <div class="text-[10px] uppercase text-slate-400">ช่างผู้รับผิดชอบ</div>
                            <div class="text-sm">{{ ticket.assignee.full_name }}</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Outsource details -->
            <div v-if="ticket.vendor_name || ticket.outsourced_at" class="card-base p-5">
                <div class="text-xs uppercase text-slate-400 tracking-wider mb-3 font-semibold">🚚 ข้อมูลการส่งซ่อมภายนอก</div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div v-if="ticket.vendor_name">
                        <dt class="text-xs text-slate-500">บริษัทผู้รับซ่อม</dt>
                        <dd class="font-medium">{{ ticket.vendor_name }}</dd>
                    </div>
                    <div v-if="ticket.outsource_ref">
                        <dt class="text-xs text-slate-500">เลขที่ใบส่งซ่อม</dt>
                        <dd class="font-mono">{{ ticket.outsource_ref }}</dd>
                    </div>
                    <div v-if="ticket.outsourced_at">
                        <dt class="text-xs text-slate-500">วันที่ส่งซ่อม</dt>
                        <dd>{{ fmtDate(ticket.outsourced_at) }}</dd>
                    </div>
                    <div v-if="ticket.expected_return_at">
                        <dt class="text-xs text-slate-500">วันคาดรับคืน</dt>
                        <dd>{{ fmtDate(ticket.expected_return_at) }}</dd>
                    </div>
                </dl>
            </div>

            <!-- Repair completion details -->
            <div v-if="ticket.action_taken || ticket.root_cause" class="card-base p-5">
                <div class="text-xs uppercase text-slate-400 tracking-wider mb-3 font-semibold">✅ ผลการซ่อม</div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div v-if="ticket.root_cause"><dt class="text-xs text-slate-500">สาเหตุ</dt><dd class="whitespace-pre-line">{{ ticket.root_cause }}</dd></div>
                    <div v-if="ticket.action_taken"><dt class="text-xs text-slate-500">วิธีแก้ไข</dt><dd class="whitespace-pre-line">{{ ticket.action_taken }}</dd></div>
                    <div v-if="ticket.parts_used"><dt class="text-xs text-slate-500">อะไหล่</dt><dd>{{ ticket.parts_used }}</dd></div>
                    <div v-if="ticket.repair_cost"><dt class="text-xs text-slate-500">ค่าซ่อม</dt><dd class="font-semibold">{{ Number(ticket.repair_cost).toLocaleString() }} บาท</dd></div>
                    <div v-if="ticket.completed_at"><dt class="text-xs text-slate-500">วันที่ซ่อมเสร็จ</dt><dd>{{ fmtDate(ticket.completed_at) }}</dd></div>
                </dl>
            </div>

            <!-- Verified info -->
            <div v-if="ticket.verified_at" class="card-base p-5">
                <div class="text-xs uppercase text-slate-400 tracking-wider mb-3 font-semibold">☑️ ตรวจรับเครื่อง</div>
                <dl class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                    <div><dt class="text-xs text-slate-500">ผู้ตรวจรับ</dt><dd>{{ ticket.verifier?.full_name || ticket.reporter?.full_name }}</dd></div>
                    <div><dt class="text-xs text-slate-500">วันที่ตรวจรับ</dt><dd>{{ fmtDate(ticket.verified_at) }}</dd></div>
                </dl>
            </div>

            <!-- Transition actions -->
            <div v-if="availableTransitions.length" class="card-base p-5">
                <div class="text-xs uppercase text-slate-400 tracking-wider mb-3 font-semibold">การดำเนินการ</div>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="s in availableTransitions"
                        :key="s"
                        @click="transitionTo(s)"
                        :class="[
                            'inline-flex items-center gap-2 px-4 py-2 rounded-xl text-sm font-medium transition',
                            s === 'CANCELLED'
                                ? 'bg-red-50 text-red-700 hover:bg-red-100 border border-red-200'
                                : s === 'VERIFIED'
                                ? 'bg-emerald-600 text-white hover:bg-emerald-700 shadow'
                                : s === 'OUTSOURCED'
                                ? 'bg-sky-600 text-white hover:bg-sky-700 shadow'
                                : 'bg-blue-600 text-white hover:bg-blue-700 shadow',
                        ]"
                    >
                        {{ STATUS_META[s]?.icon }} {{ STATUS_META[s]?.label }}
                    </button>
                </div>
                <!-- Hint for VERIFIED -->
                <p v-if="availableTransitions.includes('VERIFIED') && isReporter" class="text-xs text-slate-400 mt-2">
                    กดยืนยันรับเครื่องคืนเมื่อทดสอบแล้วว่าใช้งานได้ปกติ
                </p>
            </div>

            <div v-else-if="canManage" class="card-base p-5 text-center text-sm text-slate-400">
                ไม่มี action เพิ่มเติม (สถานะปลายทาง)
            </div>
        </template>
    </div>
</template>
