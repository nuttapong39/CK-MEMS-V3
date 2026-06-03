<script setup>
import { ref, onMounted, computed } from 'vue';
import { repairsApi } from '../../api/repairs';
import { useAuthStore } from '../../stores/auth';
import StatusBadge from '../../components/repair/StatusBadge.vue';
import { URGENCY_META } from '../../composables/repairStatus';
import {
    EyeIcon, PencilSquareIcon, XMarkIcon, TruckIcon,
} from '@heroicons/vue/24/outline';
import Swal from 'sweetalert2';

const auth = useAuthStore();
const canEdit = computed(() => auth.hasAnyRole(['admin', 'staff']));

const loading = ref(false);
const items = ref([]);

async function load() {
    loading.value = true;
    try {
        const { data } = await repairsApi.list({ status: 'OUTSOURCED', per_page: 100 });
        items.value = data.data;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

// ---- Modal state ----
const modal = ref(null);   // null | { ticket, mode: 'preview'|'edit' }
const form = ref({ action_taken: '', note: '', vendor_name: '', outsource_ref: '', expected_return_at: '' });
const saving = ref(false);

function openModal(ticket, mode) {
    form.value = {
        action_taken: ticket.action_taken ?? '',
        note: '',
        vendor_name: ticket.vendor_name ?? '',
        outsource_ref: ticket.outsource_ref ?? '',
        expected_return_at: ticket.expected_return_at
            ? ticket.expected_return_at.substring(0, 10)
            : '',
    };
    modal.value = { ticket, mode };
}

function closeModal() {
    modal.value = null;
}

async function saveRepaired() {
    if (!form.value.action_taken.trim()) {
        Swal.fire({ icon: 'warning', title: 'กรุณากรอกรายละเอียดการซ่อม', confirmButtonColor: '#3b82f6' });
        return;
    }

    const confirmed = await Swal.fire({
        icon: 'question',
        title: 'ยืนยันการซ่อมเสร็จ?',
        text: `ยืนยันว่าเครื่องมือ "${modal.value.ticket.equipment?.name_th}" ได้รับการซ่อมแซมเสร็จแล้ว`,
        showCancelButton: true,
        confirmButtonColor: '#10b981',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'ยืนยัน ซ่อมเสร็จ',
        cancelButtonText: 'ยกเลิก',
    });

    if (!confirmed.isConfirmed) return;

    saving.value = true;
    try {
        await repairsApi.transition(modal.value.ticket.id, {
            to_status: 'REPAIRED',
            action_taken: form.value.action_taken,
            note: form.value.note || null,
            vendor_name: form.value.vendor_name || null,
            outsource_ref: form.value.outsource_ref || null,
            expected_return_at: form.value.expected_return_at || null,
        });

        closeModal();
        await load();

        Swal.fire({
            icon: 'success',
            title: 'ซ่อมเสร็จเรียบร้อย!',
            text: 'รายการถูกย้ายไปยัง "ซ่อมเสร็จ" แล้ว',
            timer: 2000,
            showConfirmButton: false,
        });
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'เกิดข้อผิดพลาด', text: e?.response?.data?.message ?? 'ไม่สามารถบันทึกได้', confirmButtonColor: '#ef4444' });
    } finally {
        saving.value = false;
    }
}

function urgencyMeta(u) {
    return URGENCY_META[u] ?? { label: u, color: 'gray' };
}

function fmtDate(d) {
    if (!d) return '-';
    return new Date(d).toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
}
</script>

<template>
    <div class="p-6 max-w-7xl mx-auto">
        <!-- Header -->
        <div class="flex items-center gap-3 mb-6">
            <div class="w-10 h-10 rounded-xl bg-sky-100 flex items-center justify-center">
                <TruckIcon class="w-5 h-5 text-sky-600" />
            </div>
            <div>
                <h1 class="text-xl font-bold text-slate-800">ส่งซ่อมภายนอก</h1>
                <p class="text-sm text-slate-500">รายการเครื่องมือที่อยู่ระหว่างการส่งซ่อมกับผู้ให้บริการภายนอก</p>
            </div>
        </div>

        <!-- Loading -->
        <div v-if="loading" class="flex items-center justify-center py-20 text-slate-400">
            <svg class="animate-spin w-8 h-8 mr-3" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
            </svg>
            กำลังโหลด...
        </div>

        <!-- Empty -->
        <div v-else-if="items.length === 0" class="text-center py-20 text-slate-400">
            <TruckIcon class="w-16 h-16 mx-auto mb-3 opacity-30" />
            <p class="text-lg font-medium">ไม่มีรายการส่งซ่อมภายนอก</p>
            <p class="text-sm mt-1">รายการที่ถูกส่งซ่อมภายนอกจะปรากฏที่นี่</p>
        </div>

        <!-- Table -->
        <div v-else class="bg-white rounded-2xl shadow-sm border border-slate-200 overflow-hidden">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 border-b border-slate-200">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold text-slate-600">เลขที่งาน</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-600">เครื่องมือ</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-600">ผู้ให้บริการ</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-600">ความเร่งด่วน</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-600">วันส่ง</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-600">กำหนดรับคืน</th>
                        <th class="px-4 py-3 text-left font-semibold text-slate-600">สถานะ</th>
                        <th class="px-4 py-3 text-center font-semibold text-slate-600">ดำเนินการ</th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="ticket in items"
                        :key="ticket.id"
                        class="border-b border-slate-100 hover:bg-slate-50/50 transition-colors"
                    >
                        <td class="px-4 py-3 font-mono font-semibold text-blue-700">{{ ticket.ticket_no }}</td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-800">{{ ticket.equipment?.name_th ?? '-' }}</div>
                            <div class="text-xs text-slate-400">{{ ticket.equipment?.id_code ?? '' }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="font-medium text-slate-700">{{ ticket.vendor_name || '-' }}</div>
                            <div v-if="ticket.outsource_ref" class="text-xs text-slate-400">{{ ticket.outsource_ref }}</div>
                        </td>
                        <td class="px-4 py-3">
                            <span
                                class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-semibold"
                                :class="urgencyMeta(ticket.urgency).bg"
                            >
                                {{ urgencyMeta(ticket.urgency).label }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ fmtDate(ticket.outsourced_at) }}</td>
                        <td class="px-4 py-3">
                            <span v-if="ticket.expected_return_at" class="text-slate-600">{{ fmtDate(ticket.expected_return_at) }}</span>
                            <span v-else class="text-slate-400 text-xs">ไม่ระบุ</span>
                        </td>
                        <td class="px-4 py-3">
                            <StatusBadge :status="ticket.status" />
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-center gap-2">
                                <button
                                    @click="openModal(ticket, 'preview')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-slate-100 text-slate-600 hover:bg-slate-200 transition-colors"
                                >
                                    <EyeIcon class="w-3.5 h-3.5" />
                                    Preview
                                </button>
                                <button
                                    v-if="canEdit"
                                    @click="openModal(ticket, 'edit')"
                                    class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg text-xs font-medium bg-sky-100 text-sky-700 hover:bg-sky-200 transition-colors"
                                >
                                    <PencilSquareIcon class="w-3.5 h-3.5" />
                                    Edit
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- ======================== MODAL ======================== -->
        <Teleport to="body">
            <div
                v-if="modal"
                class="fixed inset-0 z-50 flex items-center justify-center p-4"
                @click.self="closeModal"
            >
                <!-- backdrop -->
                <div class="absolute inset-0 bg-black/40 backdrop-blur-sm" @click="closeModal"></div>

                <!-- panel -->
                <div class="relative z-10 bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-y-auto">
                    <!-- Modal header -->
                    <div class="flex items-center justify-between px-6 py-4 border-b border-slate-200 sticky top-0 bg-white rounded-t-2xl">
                        <div class="flex items-center gap-2">
                            <TruckIcon class="w-5 h-5 text-sky-600" />
                            <span class="font-bold text-slate-800">
                                {{ modal.mode === 'edit' ? 'บันทึกผลการซ่อม' : 'รายละเอียด' }}
                                —
                            </span>
                            <span class="font-mono text-blue-700 text-sm">{{ modal.ticket.ticket_no }}</span>
                        </div>
                        <button @click="closeModal" class="p-1.5 rounded-lg hover:bg-slate-100">
                            <XMarkIcon class="w-5 h-5 text-slate-500" />
                        </button>
                    </div>

                    <!-- Modal body -->
                    <div class="px-6 py-5 space-y-5">
                        <!-- Ticket info -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1">เครื่องมือ</label>
                                <p class="font-semibold text-slate-800">{{ modal.ticket.equipment?.name_th ?? '-' }}</p>
                                <p class="text-xs text-slate-400">{{ modal.ticket.equipment?.id_code ?? '' }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1">ผู้แจ้งซ่อม</label>
                                <p class="text-slate-700">{{ modal.ticket.reporter?.full_name ?? modal.ticket.reporter?.name ?? modal.ticket.reporter_name ?? '-' }}</p>
                            </div>
                            <div class="col-span-2">
                                <label class="block text-xs font-semibold text-slate-500 mb-1">อาการเสีย / ปัญหาที่พบ</label>
                                <p class="text-slate-700 bg-slate-50 rounded-lg px-3 py-2 text-sm">{{ modal.ticket.symptom }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1">ผู้ให้บริการ (Vendor)</label>
                                <p v-if="modal.mode === 'preview'" class="text-slate-700">{{ modal.ticket.vendor_name || '-' }}</p>
                                <input
                                    v-else
                                    v-model="form.vendor_name"
                                    type="text"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400"
                                    placeholder="ชื่อบริษัท / ช่างภายนอก"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1">เลขที่ใบส่งซ่อม</label>
                                <p v-if="modal.mode === 'preview'" class="text-slate-700">{{ modal.ticket.outsource_ref || '-' }}</p>
                                <input
                                    v-else
                                    v-model="form.outsource_ref"
                                    type="text"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400"
                                    placeholder="เลขอ้างอิง"
                                />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-slate-500 mb-1">กำหนดรับคืน</label>
                                <p v-if="modal.mode === 'preview'" class="text-slate-700">{{ fmtDate(modal.ticket.expected_return_at) }}</p>
                                <input
                                    v-else
                                    v-model="form.expected_return_at"
                                    type="date"
                                    class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400"
                                />
                            </div>
                        </div>

                        <!-- action_taken -->
                        <div>
                            <label class="block text-xs font-semibold text-slate-500 mb-1">
                                ซ่อมอะไรไป
                                <span v-if="modal.mode === 'edit'" class="text-rose-500">*</span>
                            </label>
                            <p
                                v-if="modal.mode === 'preview'"
                                class="text-slate-700 bg-slate-50 rounded-lg px-3 py-2 text-sm min-h-[72px] whitespace-pre-wrap"
                            >
                                {{ modal.ticket.action_taken || 'ยังไม่มีข้อมูล' }}
                            </p>
                            <textarea
                                v-else
                                v-model="form.action_taken"
                                rows="4"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400 resize-none"
                                placeholder="ระบุรายละเอียดการซ่อม เช่น เปลี่ยนอะไหล่ ทำความสะอาด ฯลฯ (จำเป็น)"
                            ></textarea>
                        </div>

                        <!-- note (edit only) -->
                        <div v-if="modal.mode === 'edit'">
                            <label class="block text-xs font-semibold text-slate-500 mb-1">หมายเหตุเพิ่มเติม</label>
                            <textarea
                                v-model="form.note"
                                rows="2"
                                class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-sky-400 resize-none"
                                placeholder="หมายเหตุ (ถ้ามี)"
                            ></textarea>
                        </div>
                    </div>

                    <!-- Modal footer -->
                    <div class="px-6 py-4 border-t border-slate-200 flex justify-end gap-3 sticky bottom-0 bg-white rounded-b-2xl">
                        <button
                            @click="closeModal"
                            class="px-4 py-2 rounded-lg text-sm font-medium bg-slate-100 text-slate-600 hover:bg-slate-200 transition-colors"
                        >
                            {{ modal.mode === 'preview' ? 'ปิด' : 'ยกเลิก' }}
                        </button>
                        <button
                            v-if="modal.mode === 'edit'"
                            @click="saveRepaired"
                            :disabled="saving"
                            class="px-5 py-2 rounded-lg text-sm font-semibold bg-emerald-500 text-white hover:bg-emerald-600 disabled:opacity-50 disabled:cursor-not-allowed transition-colors flex items-center gap-2"
                        >
                            <svg v-if="saving" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            {{ saving ? 'กำลังบันทึก...' : 'บันทึก — ซ่อมเสร็จ ✓' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </div>
</template>
