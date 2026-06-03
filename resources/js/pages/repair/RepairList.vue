<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import { RouterLink, useRoute } from 'vue-router';
import {
    PlusIcon, MagnifyingGlassIcon, ChevronLeftIcon, ChevronRightIcon,
    EyeIcon, ArrowDownTrayIcon, Cog8ToothIcon, TrashIcon, ShieldExclamationIcon,
} from '@heroicons/vue/24/outline';
import Swal from 'sweetalert2';
import { repairsApi } from '../../api/repairs';
import { useAuthStore } from '../../stores/auth';
import StatusBadge from '../../components/repair/StatusBadge.vue';
import { URGENCY_META } from '../../composables/repairStatus';

const auth = useAuthStore();
const route = useRoute();
const canManage = computed(() => auth.hasAnyRole(['admin', 'staff']));

/* Manage mode — เข้าผ่านปุ่ม "งานซ่อมทั้งหมด" (/repair?manage=1) เท่านั้น
   เปิดให้ staff/admin ลบงานซ่อมได้ทุกสถานะ (กรณีแจ้งซ้ำ/ทดสอบ/แจ้งผิด) */
const manageMode = computed(() => canManage.value && route.query.manage === '1');

async function deleteTicket(t) {
    const res = await Swal.fire({
        icon: 'warning',
        title: 'ลบงานซ่อมนี้?',
        html: `หมายเลข <b>${t.ticket_no}</b><br>${t.equipment?.id_code ?? ''} ${t.equipment?.name_th ?? ''}<br><span style="color:#dc2626">การลบนี้ถาวร ไม่สามารถกู้คืนได้</span>`,
        showCancelButton: true,
        confirmButtonText: 'ลบถาวร',
        cancelButtonText: 'ยกเลิก',
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#64748b',
    });
    if (!res.isConfirmed) return;
    try {
        await repairsApi.destroy(t.id);
        await Swal.fire({ icon: 'success', title: 'ลบเรียบร้อย', timer: 1200, showConfirmButton: false });
        // ถ้าลบรายการสุดท้ายของหน้า ให้ถอยหน้า
        if (items.value.length === 1 && filters.page > 1) filters.page--;
        else load();
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'ลบไม่สำเร็จ', text: e.response?.data?.message || '' });
    }
}
const loading = ref(false);
const items = ref([]);
const meta = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 });

const filters = reactive({
    search: '',
    status: '',
    urgency: '',
    page: 1,
    per_page: 25,
});

async function load() {
    loading.value = true;
    try {
        const params = {};
        for (const [k, v] of Object.entries(filters)) {
            if (v !== '' && v !== null && v !== undefined) params[k] = v;
        }
        const { data } = await repairsApi.list(params);
        items.value = data.data;
        meta.value = data.meta;
    } finally {
        loading.value = false;
    }
}

onMounted(load);

let debounceId = null;
watch(() => filters.search, () => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => { filters.page = 1; load(); }, 300);
});
watch(() => [filters.status, filters.urgency, filters.per_page], () => {
    filters.page = 1;
    load();
});
watch(() => filters.page, load);

const pageRange = computed(() => {
    const cur = meta.value.current_page;
    const last = meta.value.last_page;
    const start = Math.max(1, cur - 2);
    const end = Math.min(last, cur + 2);
    const pages = [];
    for (let i = start; i <= end; i++) pages.push(i);
    return pages;
});

function exportCsv() {
    const headers = ['Ticket No', 'Date', 'Equipment', 'Department', 'Symptom', 'Urgency', 'Status', 'Reporter'];
    const rows = items.value.map((t) => [
        t.ticket_no,
        new Date(t.reported_at).toLocaleString('th-TH'),
        `${t.equipment?.id_code ?? ''} ${t.equipment?.name_th ?? ''}`,
        t.equipment?.department?.code ?? '',
        (t.symptom || '').replace(/[\r\n]+/g, ' '),
        t.urgency,
        t.status,
        t.reporter?.full_name ?? t.reporter_name ?? '',
    ]);
    const csv = [headers, ...rows]
        .map((r) => r.map((c) => `"${String(c).replace(/"/g, '""')}"`).join(','))
        .join('\r\n');
    const blob = new Blob(['﻿' + csv], { type: 'text/csv;charset=utf-8;' });
    const a = document.createElement('a');
    a.href = URL.createObjectURL(blob);
    a.download = `repair_history_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
}
</script>

<template>
    <div class="space-y-5">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-slate-800">ประวัติการแจ้งซ่อม</h1>
                <p class="text-xs text-slate-500 mt-0.5">ทั้งหมด {{ meta.total }} รายการ</p>
            </div>
            <div class="flex gap-2">
                <button
                    @click="exportCsv"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-white border border-slate-200 hover:bg-slate-50 transition text-sm font-medium text-slate-700"
                >
                    <ArrowDownTrayIcon class="w-4 h-4" />
                    Export CSV
                </button>
                <RouterLink
                    :to="{ name: 'repair.create' }"
                    class="inline-flex items-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r from-rose-500 to-orange-500 text-white font-semibold shadow-lg shadow-rose-500/30 hover:shadow-xl transition"
                >
                    <PlusIcon class="w-5 h-5" />
                    แจ้งซ่อม
                </RouterLink>
            </div>
        </div>

        <!-- Manage mode banner -->
        <div v-if="manageMode" class="flex items-center gap-2.5 px-4 py-3 rounded-xl bg-rose-50 border border-rose-200 text-rose-700">
            <ShieldExclamationIcon class="w-5 h-5 shrink-0" />
            <div class="text-sm">
                <b>โหมดจัดการงานซ่อม</b> — สามารถลบงานซ่อมได้ทุกสถานะ (สำหรับกรณีแจ้งซ้ำ / ทดสอบระบบ / แจ้งผิด)
                <span class="text-rose-500">การลบเป็นการลบถาวร</span>
            </div>
        </div>

        <!-- Filters -->
        <div class="card-base p-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                <div class="relative">
                    <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                    <input
                        v-model="filters.search"
                        type="text"
                        placeholder="ค้นหา ticket / อาการ / ID เครื่อง"
                        class="w-full pl-9 pr-3 py-2 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition text-sm"
                    />
                </div>
                <select v-model="filters.status" class="px-3 py-2 rounded-xl border border-slate-200 outline-none text-sm bg-white">
                    <option value="">ทุกสถานะ</option>
                    <option value="PENDING">⏳ รอรับเรื่อง</option>
                    <option value="ACKNOWLEDGED">📋 รับเรื่องแล้ว</option>
                    <option value="IN_PROGRESS">🔧 กำลังซ่อม</option>
                    <option value="WAITING_PARTS">📦 รออะไหล่</option>
                    <option value="OUTSOURCED">🚚 ส่งซ่อมภายนอก</option>
                    <option value="REPAIRED">✅ ซ่อมเสร็จ</option>
                    <option value="VERIFIED">☑️ ตรวจรับแล้ว</option>
                    <option value="CLOSED">📁 ปิดงาน</option>
                    <option value="CANCELLED">✖ ยกเลิก</option>
                </select>
                <select v-model="filters.urgency" class="px-3 py-2 rounded-xl border border-slate-200 outline-none text-sm bg-white">
                    <option value="">ทุกระดับเร่งด่วน</option>
                    <option v-for="(meta, key) in URGENCY_META" :key="key" :value="key">{{ meta.label }}</option>
                </select>
                <select v-model.number="filters.per_page" class="px-3 py-2 rounded-xl border border-slate-200 outline-none text-sm bg-white">
                    <option :value="10">10/หน้า</option>
                    <option :value="25">25/หน้า</option>
                    <option :value="50">50/หน้า</option>
                </select>
            </div>
        </div>

        <!-- Table -->
        <div class="card-base overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr class="text-left text-xs uppercase tracking-wider text-slate-500">
                            <th class="px-5 py-3 font-semibold">หมายเลข</th>
                            <th class="px-5 py-3 font-semibold">วันที่แจ้ง</th>
                            <th class="px-5 py-3 font-semibold">เครื่องมือ</th>
                            <th class="px-5 py-3 font-semibold">อาการ</th>
                            <th class="px-5 py-3 font-semibold">เร่งด่วน</th>
                            <th class="px-5 py-3 font-semibold">สถานะ</th>
                            <th class="px-5 py-3 font-semibold">ผู้แจ้ง</th>
                            <th class="px-5 py-3 font-semibold text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <tr v-if="loading">
                            <td colspan="8" class="px-5 py-12 text-center text-sm text-slate-400">กำลังโหลด...</td>
                        </tr>
                        <tr v-else-if="!items.length">
                            <td colspan="8" class="px-5 py-12 text-center text-sm text-slate-400">
                                ยังไม่มีประวัติการซ่อม
                            </td>
                        </tr>
                        <tr v-for="t in items" :key="t.id" :class="['hover:bg-slate-50 transition-colors', t.sla_overdue ? 'bg-red-50/40' : '']">
                            <td class="px-5 py-3">
                                <RouterLink :to="{ name: 'repair.detail', params: { id: t.id } }" class="font-mono text-xs font-semibold text-blue-700 hover:underline">
                                    {{ t.ticket_no }}
                                </RouterLink>
                                <span v-if="t.sla_overdue" class="ml-1 text-[10px] bg-red-100 text-red-600 px-1.5 py-0.5 rounded-full font-medium">SLA</span>
                            </td>
                            <td class="px-5 py-3 text-slate-600 text-xs whitespace-nowrap">
                                {{ new Date(t.reported_at).toLocaleString('th-TH', { dateStyle: 'short', timeStyle: 'short' }) }}
                            </td>
                            <td class="px-5 py-3">
                                <div class="font-mono text-xs text-blue-700">{{ t.equipment?.id_code }}</div>
                                <div class="text-xs text-slate-600 truncate max-w-[200px]">{{ t.equipment?.name_th }}</div>
                            </td>
                            <td class="px-5 py-3 text-slate-600 text-xs max-w-[260px] truncate">{{ t.symptom }}</td>
                            <td class="px-5 py-3">
                                <span :class="['inline-flex items-center gap-1.5 text-[10px] px-2 py-1 rounded-full font-medium text-white', URGENCY_META[t.urgency]?.bg]">
                                    {{ URGENCY_META[t.urgency]?.label }}
                                </span>
                            </td>
                            <td class="px-5 py-3"><StatusBadge :status="t.status" /></td>
                            <td class="px-5 py-3 text-xs text-slate-600">
                                {{ t.reporter?.full_name ?? (t.reporter_name ? t.reporter_name + ' (QR)' : '—') }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="inline-flex items-center gap-1">
                                    <!-- จัดการซ่อม: admin/staff + ticket ยังไม่ปิด -->
                                    <RouterLink
                                        v-if="canManage && !['CLOSED','CANCELLED'].includes(t.status)"
                                        :to="{ name: 'repair.process', params: { id: t.id } }"
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg bg-blue-600 text-white text-xs font-medium hover:bg-blue-700 transition"
                                        title="จัดการซ่อม"
                                    >
                                        <Cog8ToothIcon class="w-3.5 h-3.5" />
                                        จัดการ
                                    </RouterLink>
                                    <RouterLink :to="{ name: 'repair.detail', params: { id: t.id } }" class="inline-flex p-1.5 rounded-lg hover:bg-slate-100 text-slate-500" title="ดูรายละเอียด">
                                        <EyeIcon class="w-4 h-4" />
                                    </RouterLink>
                                    <!-- ลบงานซ่อม: เฉพาะ manage mode (staff/admin) ลบได้ทุกสถานะ -->
                                    <button
                                        v-if="manageMode"
                                        @click="deleteTicket(t)"
                                        class="inline-flex p-1.5 rounded-lg hover:bg-rose-100 text-rose-500"
                                        title="ลบงานซ่อม"
                                    >
                                        <TrashIcon class="w-4 h-4" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div v-if="meta.last_page > 1" class="flex items-center justify-between px-5 py-4 border-t border-slate-100">
                <div class="text-xs text-slate-500">หน้า {{ meta.current_page }} / {{ meta.last_page }}</div>
                <div class="flex items-center gap-1">
                    <button :disabled="filters.page === 1" @click="filters.page--" class="p-2 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-40">
                        <ChevronLeftIcon class="w-4 h-4" />
                    </button>
                    <button v-for="p in pageRange" :key="p" @click="filters.page = p"
                        :class="['min-w-[32px] h-8 px-2 rounded-lg text-sm font-medium', p === meta.current_page ? 'bg-blue-600 text-white' : 'border border-slate-200 hover:bg-slate-50']">
                        {{ p }}
                    </button>
                    <button :disabled="filters.page === meta.last_page" @click="filters.page++" class="p-2 rounded-lg border border-slate-200 hover:bg-slate-50 disabled:opacity-40">
                        <ChevronRightIcon class="w-4 h-4" />
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
