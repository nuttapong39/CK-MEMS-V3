<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue';
import {
    QrCodeIcon, MagnifyingGlassIcon, PrinterIcon, BookmarkSquareIcon,
    Squares2X2Icon, ChevronLeftIcon, ChevronRightIcon, PaintBrushIcon,
    WrenchScrewdriverIcon,
} from '@heroicons/vue/24/outline';
import Swal from 'sweetalert2';
import QRCode from 'qrcode';
import jsPDF from 'jspdf';
import html2canvas from 'html2canvas';
import { useMasterStore } from '../../stores/master';
import { equipmentsApi } from '../../api/equipments';
import { qrcodeApi } from '../../api/qrcode';
import LabelDesigner from '../../components/qrcode/LabelDesigner.vue';

const master = useMasterStore();

/* ─────────────────────────────────────────────
   SHARED STATE
───────────────────────────────────────────── */
const activeTab = ref('print'); // 'print' | 'design' | 'repair'

/* QR URL builders — detail page vs. public repair-report page */
const detailUrl = (idCode) => `${window.location.origin}/qr/${idCode}`;
const repairUrl = (idCode) => `${window.location.origin}/qr/repair/${idCode}`;

/* ─────────────────────────────────────────────
   EQUIPMENT LIST (Tab 1)
───────────────────────────────────────────── */
const items   = ref([]);
const meta    = ref({ current_page: 1, last_page: 1, total: 0, per_page: 25 });
const loading = ref(false);
const filters = reactive({ search: '', department_id: '', page: 1, per_page: 25 });
const selected = ref(new Set());

const allFields = [
    { key: 'id_code',       label: 'รหัสเครื่องมือ (ID Code)' },
    { key: 'name_th',       label: 'ชื่อภาษาไทย' },
    { key: 'name_en',       label: 'ชื่อภาษาอังกฤษ' },
    { key: 'manufacturer',  label: 'ยี่ห้อ' },
    { key: 'model',         label: 'รุ่น' },
    { key: 'serial_number', label: 'Serial Number' },
    { key: 'department',    label: 'หน่วยงาน' },
];

const layout = ref({
    paper_size: 'a4',
    qr_size_mm: 35,
    fields_to_show: ['id_code', 'name_th', 'manufacturer', 'model', 'department'],
});
const templates = ref([]);

async function load() {
    loading.value = true;
    try {
        const params = {};
        for (const [k, v] of Object.entries(filters)) {
            if (v !== '' && v !== null) params[k] = v;
        }
        const { data } = await equipmentsApi.list(params);
        items.value   = data.data;
        meta.value    = data.meta;
    } finally {
        loading.value = false;
    }
}

async function loadTemplates() {
    try {
        const { data } = await qrcodeApi.templates();
        templates.value = data;
        const def = data.find((t) => t.is_default);
        if (def) applyTemplate(def);
    } catch { /* ignore */ }
}

onMounted(async () => {
    await master.loadAll();
    await Promise.all([load(), loadTemplates()]);
});

let debounceId = null;
watch(() => filters.search, () => {
    clearTimeout(debounceId);
    debounceId = setTimeout(() => { filters.page = 1; load(); }, 300);
});
watch(() => [filters.department_id, filters.per_page], () => { filters.page = 1; load(); });
watch(() => filters.page, load);

function toggleSelect(id) {
    const s = new Set(selected.value);
    s.has(id) ? s.delete(id) : s.add(id);
    selected.value = s;
}
const isSelected = (id) => selected.value.has(id);
function selectAllOnPage() { const s = new Set(selected.value); items.value.forEach((it) => s.add(it.id)); selected.value = s; }
function clearSelection() { selected.value = new Set(); }

function applyTemplate(t) {
    layout.value = {
        paper_size: t.paper_size,
        qr_size_mm: t.qr_size_mm,
        fields_to_show: Array.isArray(t.fields_to_show) ? [...t.fields_to_show] : t.fields_to_show,
    };
}

async function saveTemplate() {
    const { value: name } = await Swal.fire({
        title: 'บันทึก Template', input: 'text', inputLabel: 'ชื่อ template',
        inputValue: 'Template ใหม่', showCancelButton: true,
        confirmButtonText: 'บันทึก', cancelButtonText: 'ยกเลิก', confirmButtonColor: '#2563eb',
    });
    if (!name) return;
    try {
        await qrcodeApi.storeTemplate({ ...layout.value, name, is_default: false });
        Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 1200, showConfirmButton: false });
        loadTemplates();
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'ไม่สำเร็จ', text: e.response?.data?.message || '' });
    }
}

/* ── Client-side QR generation ── */
const qrCache = reactive({});
async function getQrDataUrl(idCode, size = 200) {
    const key = `${idCode}_${size}`;
    if (qrCache[key]) return qrCache[key];
    const url = detailUrl(idCode);
    const dataUrl = await QRCode.toDataURL(url, {
        width: size, margin: 1,
        color: { dark: '#000000', light: '#ffffff' },
        errorCorrectionLevel: 'H',
    });
    qrCache[key] = dataUrl;
    return dataUrl;
}

/* Reactive map: id → dataUrl */
const qrDataUrls = reactive({});
const previewItems = computed(() => items.value.filter((it) => selected.value.has(it.id)).slice(0, 6));

watch(previewItems, async (newItems) => {
    for (const it of newItems) {
        if (!qrDataUrls[it.id]) {
            qrDataUrls[it.id] = await getQrDataUrl(it.id_code, 240);
        }
    }
}, { immediate: true });

/* ── Client-side PDF generation (html2canvas → jsPDF, renders Thai correctly) ── */
const generatingPdf = ref(false);
const printItems    = computed(() => items.value.filter((it) => selected.value.has(it.id)));

function buildLabelHtml(it, qUrl, qMm, fields) {
    const fieldLabels = {
        name_th:       'ชื่อ',
        name_en:       'Name',
        manufacturer:  'ยี่ห้อ',
        model:         'รุ่น',
        serial_number: 'SN',
        department:    'หน่วยงาน',
    };
    const fieldValues = {
        name_th:       it.name_th || '',
        name_en:       it.name_en || '',
        manufacturer:  it.manufacturer || '',
        model:         it.model || '',
        serial_number: it.serial_number || '',
        department:    it.department?.name_th || '',
    };

    const px   = 3.78; // 1mm ≈ 3.78px at 96dpi
    const qPx  = qMm * px;
    const rows = fields
        .filter(k => k !== 'id_code' && fieldValues[k])
        .map(k => `
            <div style="font-size:9px;color:#334155;line-height:1.3;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                <span style="color:#94a3b8;font-size:7px;">${fieldLabels[k] || k}:</span> ${fieldValues[k]}
            </div>`)
        .join('');

    return `
        <div style="font-family:sans-serif;padding:6px;background:#fff;border:1px dashed #94a3b8;border-radius:4px;box-sizing:border-box;">
            <img src="${qUrl}" width="${qPx}" height="${qPx}" style="display:block;margin:0 auto 4px;" />
            <div style="font-family:monospace;font-weight:bold;font-size:11px;color:#1d4ed8;text-align:center;margin-bottom:3px;">${it.id_code}</div>
            ${rows}
        </div>`;
}

async function generatePdf() {
    if (selected.value.size === 0) {
        Swal.fire({ icon: 'warning', title: 'เลือกเครื่องมืออย่างน้อย 1 รายการ' });
        return;
    }
    generatingPdf.value = true;

    // Pre-generate all QR data URLs
    for (const it of printItems.value) {
        if (!qrDataUrls[it.id]) {
            qrDataUrls[it.id] = await getQrDataUrl(it.id_code, 420);
        }
    }

    const container = document.createElement('div');
    container.style.cssText = 'position:fixed;left:-9999px;top:0;z-index:-1;pointer-events:none;';
    document.body.appendChild(container);

    try {
        const paperMap = { a4: [210, 297], a5: [148, 210], letter: [215.9, 279.4], legal: [215.9, 355.6] };
        const [pw, ph]  = paperMap[layout.value.paper_size] ?? [210, 297];
        const pdf       = new jsPDF({ orientation: 'portrait', unit: 'mm', format: [pw, ph] });

        const margin   = 8;
        const gap      = 4;
        const qMm      = layout.value.qr_size_mm;
        const labelW   = qMm + 32;
        const labelH   = qMm + layout.value.fields_to_show.filter(k => k !== 'id_code').length * 5 + 16;
        const cols     = Math.max(1, Math.floor((pw - margin * 2 + gap) / (labelW + gap)));
        const px       = 3.78;

        let col = 0, row = 0, firstPage = true;

        for (const it of printItems.value) {
            const cx = margin + col * (labelW + gap);
            const cy = margin + row * (labelH + gap);

            if (!firstPage && cy + labelH > ph - margin) {
                pdf.addPage(); col = 0; row = 0;
            }
            firstPage = false;

            const fcx = margin + col * (labelW + gap);
            const fcy = margin + row * (labelH + gap);

            // Render label to DOM, capture with html2canvas
            const wrapper = document.createElement('div');
            wrapper.style.cssText = `width:${labelW * px}px;`;
            wrapper.innerHTML = buildLabelHtml(it, qrDataUrls[it.id] || '', qMm, layout.value.fields_to_show);
            container.appendChild(wrapper);

            const canvas = await html2canvas(wrapper, { scale: 2, useCORS: true, logging: false, backgroundColor: '#ffffff' });
            const imgData = canvas.toDataURL('image/png');
            pdf.addImage(imgData, 'PNG', fcx, fcy, labelW, labelH);
            container.removeChild(wrapper);

            col++;
            if (col >= cols) { col = 0; row++; }
        }

        pdf.save(`qrcodes_${new Date().toISOString().slice(0, 10)}.pdf`);
    } catch (e) {
        console.error(e);
        Swal.fire({ icon: 'error', title: 'สร้าง PDF ไม่สำเร็จ', text: String(e) });
    } finally {
        document.body.removeChild(container);
        generatingPdf.value = false;
    }
}

const pageRange = computed(() => {
    const cur = meta.value.current_page, last = meta.value.last_page;
    const start = Math.max(1, cur - 2), end = Math.min(last, cur + 2);
    const arr = [];
    for (let i = start; i <= end; i++) arr.push(i);
    return arr;
});

</script>

<template>
    <div class="space-y-4">

        <!-- Header -->
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-xl bg-violet-100 text-violet-600 flex items-center justify-center shrink-0">
                    <QrCodeIcon class="w-6 h-6" />
                </div>
                <div>
                    <h1 class="text-xl font-bold text-slate-800">QR Code</h1>
                    <p class="text-xs text-slate-500 mt-0.5">พิมพ์ · ออกแบบป้าย · QR แจ้งซ่อมเครื่องมือแพทย์</p>
                </div>
            </div>
            <div class="text-sm text-slate-500 hidden sm:block">
                เลือกแล้ว <span class="font-bold text-blue-600">{{ selected.size }}</span> / {{ meta.total }}
            </div>
        </div>

        <!-- Tabs -->
        <div class="flex gap-1 p-1 bg-slate-100 rounded-xl w-fit">
            <button @click="activeTab = 'print'"
                :class="['px-4 py-2 rounded-lg text-sm font-medium transition', activeTab === 'print' ? 'bg-white text-blue-600 shadow' : 'text-slate-600 hover:text-slate-800']">
                <span class="flex items-center gap-2"><PrinterIcon class="w-4 h-4" /> พิมพ์ QR Code</span>
            </button>
            <button @click="activeTab = 'design'"
                :class="['px-4 py-2 rounded-lg text-sm font-medium transition', activeTab === 'design' ? 'bg-white text-violet-600 shadow' : 'text-slate-600 hover:text-slate-800']">
                <span class="flex items-center gap-2"><PaintBrushIcon class="w-4 h-4" /> ออกแบบป้าย (Drag &amp; Drop)</span>
            </button>
            <button @click="activeTab = 'repair'"
                :class="['px-4 py-2 rounded-lg text-sm font-medium transition', activeTab === 'repair' ? 'bg-white text-rose-600 shadow' : 'text-slate-600 hover:text-slate-800']">
                <span class="flex items-center gap-2"><WrenchScrewdriverIcon class="w-4 h-4" /> QR แจ้งซ่อม (Drag &amp; Drop)</span>
            </button>
        </div>

        <!-- ═══════════════════════════════
             TAB 1: PRINT QR
        ════════════════════════════════ -->
        <div v-if="activeTab === 'print'" class="grid grid-cols-1 lg:grid-cols-12 gap-5">

            <!-- Equipment List -->
            <div class="lg:col-span-7 space-y-3">
                <div class="card-base p-3">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                        <div class="relative sm:col-span-2">
                            <MagnifyingGlassIcon class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                            <input v-model="filters.search" placeholder="ค้นหา ID / ชื่อ / ยี่ห้อ"
                                class="w-full pl-9 pr-3 py-2 rounded-xl border border-slate-200 text-sm focus:border-blue-500 outline-none" />
                        </div>
                        <select v-model.number="filters.department_id" class="px-3 py-2 rounded-xl border border-slate-200 text-sm bg-white">
                            <option value="">ทุกหน่วยงาน</option>
                            <option v-for="d in master.departments" :key="d.id" :value="d.id">{{ d.code }} — {{ d.name_th }}</option>
                        </select>
                    </div>
                    <div class="flex items-center gap-2 mt-3 text-xs">
                        <button @click="selectAllOnPage" class="text-blue-600 hover:underline">เลือกทั้งหน้า</button>
                        <span class="text-slate-300">|</span>
                        <button @click="clearSelection" class="text-rose-600 hover:underline">ล้างการเลือก</button>
                    </div>
                </div>

                <div class="card-base overflow-hidden">
                    <div v-if="loading" class="p-12 text-center text-slate-400 text-sm">กำลังโหลด...</div>
                    <div v-else-if="!items.length" class="p-12 text-center text-slate-400 text-sm">ไม่พบเครื่องมือ</div>
                    <ul v-else class="divide-y divide-slate-100 max-h-[420px] overflow-y-auto">
                        <li v-for="it in items" :key="it.id"
                            class="px-4 py-2.5 flex items-center gap-3 hover:bg-slate-50 cursor-pointer transition"
                            @click="toggleSelect(it.id)">
                            <input type="checkbox" :checked="isSelected(it.id)" class="w-4 h-4 accent-blue-600" />
                            <div class="font-mono text-xs text-blue-700 w-28 shrink-0">{{ it.id_code }}</div>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm text-slate-800 truncate">{{ it.name_th }}</div>
                                <div class="text-xs text-slate-500">{{ it.manufacturer }} {{ it.model }}</div>
                            </div>
                            <span class="text-xs px-2 py-0.5 rounded-full bg-slate-100 text-slate-600">{{ it.department?.code }}</span>
                        </li>
                    </ul>
                    <div v-if="meta.last_page > 1" class="flex items-center justify-between px-4 py-3 border-t border-slate-100">
                        <div class="text-xs text-slate-500">หน้า {{ meta.current_page }} / {{ meta.last_page }}</div>
                        <div class="flex items-center gap-1">
                            <button :disabled="filters.page === 1" @click="filters.page--" class="p-1.5 rounded-lg border border-slate-200 disabled:opacity-40"><ChevronLeftIcon class="w-4 h-4" /></button>
                            <button v-for="p in pageRange" :key="p" @click="filters.page = p"
                                :class="['min-w-[28px] h-7 px-2 rounded-lg text-xs', p === meta.current_page ? 'bg-blue-600 text-white' : 'border border-slate-200']">{{ p }}</button>
                            <button :disabled="filters.page === meta.last_page" @click="filters.page++" class="p-1.5 rounded-lg border border-slate-200 disabled:opacity-40"><ChevronRightIcon class="w-4 h-4" /></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Settings + Preview -->
            <div class="lg:col-span-5 space-y-3">
                <!-- Settings -->
                <div class="card-base p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <div class="text-sm font-semibold text-slate-800">การตั้งค่าใบ QR</div>
                        <select v-if="templates.length" @change="(e) => { const t = templates.find(t => t.id === +e.target.value); if(t) applyTemplate(t); }"
                            class="text-xs px-2 py-1 rounded-lg border border-slate-200 bg-white">
                            <option value="">โหลด template...</option>
                            <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
                        </select>
                    </div>
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="text-xs text-slate-500 block mb-1">ขนาดกระดาษ</label>
                            <select v-model="layout.paper_size" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm bg-white">
                                <option value="a4">A4 (210×297)</option>
                                <option value="a5">A5 (148×210)</option>
                                <option value="letter">Letter</option>
                                <option value="legal">Legal</option>
                            </select>
                        </div>
                        <div>
                            <label class="text-xs text-slate-500 block mb-1">QR Size: <span class="font-bold text-blue-600">{{ layout.qr_size_mm }} mm</span></label>
                            <input v-model.number="layout.qr_size_mm" type="range" min="20" max="80" step="1" class="w-full accent-blue-600" />
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-slate-500 block mb-2">Fields ที่จะแสดงบนใบ</label>
                        <div class="grid grid-cols-2 gap-1">
                            <label v-for="f in allFields" :key="f.key" class="flex items-center gap-2 px-2 py-1.5 rounded-lg hover:bg-slate-50 cursor-pointer">
                                <input type="checkbox" :value="f.key" v-model="layout.fields_to_show" class="w-3.5 h-3.5 accent-blue-600" />
                                <span class="text-xs text-slate-700">{{ f.label }}</span>
                            </label>
                        </div>
                    </div>
                    <div class="flex gap-2 pt-2 border-t border-slate-100">
                        <button @click="saveTemplate" class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl border-2 border-blue-200 text-blue-700 text-sm font-medium hover:bg-blue-50 transition">
                            <BookmarkSquareIcon class="w-4 h-4" /> บันทึก Template
                        </button>
                        <button @click="generatePdf" :disabled="selected.size === 0 || generatingPdf"
                            class="flex-1 inline-flex items-center justify-center gap-1.5 px-3 py-2 rounded-xl bg-gradient-to-r from-violet-500 to-fuchsia-500 text-white text-sm font-semibold shadow disabled:opacity-50 transition">
                            <svg v-if="generatingPdf" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                            <PrinterIcon v-else class="w-4 h-4" />
                            {{ generatingPdf ? 'กำลังสร้าง...' : 'สร้าง PDF' }}
                        </button>
                    </div>
                </div>

                <!-- Preview -->
                <div class="card-base p-4">
                    <div class="flex items-center gap-2 mb-3">
                        <Squares2X2Icon class="w-4 h-4 text-violet-500" />
                        <div class="text-sm font-semibold text-slate-800">Preview ({{ Math.min(selected.size, 6) }} ตัวอย่าง)</div>
                    </div>
                    <div v-if="!previewItems.length" class="py-10 text-center text-slate-400 text-sm">เลือกเครื่องมือก่อน</div>
                    <div v-else class="grid grid-cols-2 gap-2">
                        <div v-for="it in previewItems" :key="it.id"
                            class="border border-dashed border-slate-300 rounded-xl p-2 text-center bg-white">
                            <img v-if="qrDataUrls[it.id]" :src="qrDataUrls[it.id]" class="block mx-auto mb-1"
                                :style="{ width: layout.qr_size_mm * 2 + 'px', height: layout.qr_size_mm * 2 + 'px' }" />
                            <div v-else class="mx-auto mb-1 bg-slate-100 rounded flex items-center justify-center text-slate-300 text-xs"
                                :style="{ width: layout.qr_size_mm * 2 + 'px', height: layout.qr_size_mm * 2 + 'px' }">กำลังโหลด QR...</div>
                            <div class="font-mono text-[10px] font-bold text-blue-700">{{ it.id_code }}</div>
                            <div v-if="layout.fields_to_show.includes('name_th')" class="text-[10px] text-slate-700 truncate">{{ it.name_th }}</div>
                            <div v-if="layout.fields_to_show.includes('manufacturer')" class="text-[9px] text-slate-500 truncate">{{ it.manufacturer }}</div>
                            <div v-if="layout.fields_to_show.includes('department')" class="text-[9px] text-slate-400">{{ it.department?.name_th }}</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══════════════════════════════
             TAB 2: DRAG & DROP DESIGNER (QR → equipment detail)
        ════════════════════════════════ -->
        <LabelDesigner
            v-if="activeTab === 'design'"
            :items="items"
            :build-url="detailUrl"
            accent-gradient="from-violet-500 to-fuchsia-500"
            file-prefix="label_design"
            hint="ออกแบบป้าย QR เครื่องมือแพทย์ — สแกนแล้วแสดงรายละเอียดเครื่องมือ"
        />

        <!-- ═══════════════════════════════
             TAB 3: DRAG & DROP DESIGNER (QR → public repair report)
        ════════════════════════════════ -->
        <LabelDesigner
            v-if="activeTab === 'repair'"
            :items="items"
            :build-url="repairUrl"
            accent-gradient="from-rose-500 to-orange-500"
            file-prefix="repair_qr"
            hint="ออกแบบป้าย QR แจ้งซ่อม — สแกนแล้วเปิดฟอร์มแจ้งซ่อมของเครื่องมือนั้นทันที (ไม่ต้อง login)"
        />

    </div>
</template>
