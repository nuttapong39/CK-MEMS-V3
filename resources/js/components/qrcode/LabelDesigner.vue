<script setup>
import { computed, onUnmounted, reactive, ref, watch } from 'vue';
import {
    PaintBrushIcon, PlusIcon, TrashIcon,
    ArrowsPointingOutIcon, DocumentTextIcon, AdjustmentsHorizontalIcon,
    XMarkIcon, PrinterIcon, QrCodeIcon,
} from '@heroicons/vue/24/outline';
import Swal from 'sweetalert2';
import QRCode from 'qrcode';
import jsPDF from 'jspdf';
import html2canvas from 'html2canvas';

const props = defineProps({
    // Equipment list (already loaded by parent)
    items: { type: Array, default: () => [] },
    // (idCode) => string : the URL the QR code encodes
    buildUrl: { type: Function, required: true },
    // Literal Tailwind gradient classes for the export button
    accentGradient: { type: String, default: 'from-violet-500 to-fuchsia-500' },
    // PDF filename prefix
    filePrefix: { type: String, default: 'label_design' },
    // Optional hint shown above the canvas
    hint: { type: String, default: '' },
});

const SCALE = 3.5; // px per mm for canvas display

const labelSize = reactive({ w: 90, h: 55 }); // mm

const designerFields = [
    { key: 'qr',           label: 'QR Code',           icon: '📱', defaultW: 28, defaultH: 28 },
    { key: 'id_code',      label: 'รหัสเครื่องมือ',     icon: '🔵', defaultW: 50, defaultH: 6  },
    { key: 'name_th',      label: 'ชื่อภาษาไทย',        icon: '📝', defaultW: 60, defaultH: 5  },
    { key: 'name_en',      label: 'ชื่ออังกฤษ',          icon: '📝', defaultW: 60, defaultH: 5  },
    { key: 'manufacturer', label: 'ยี่ห้อ',              icon: '🏭', defaultW: 40, defaultH: 5  },
    { key: 'model',        label: 'รุ่น',                icon: '⚙️',  defaultW: 40, defaultH: 5  },
    { key: 'serial_number',label: 'Serial Number',      icon: '#️⃣', defaultW: 50, defaultH: 5  },
    { key: 'department',   label: 'หน่วยงาน',            icon: '🏥', defaultW: 50, defaultH: 5  },
    { key: 'fiscal_year',  label: 'ปีงบประมาณ',          icon: '📅', defaultW: 30, defaultH: 5  },
    { key: '__text__',     label: 'ข้อความอิสระ',         icon: '✏️',  defaultW: 50, defaultH: 5  },
    { key: '__hospital__', label: 'ชื่อโรงพยาบาล',       icon: '🏨', defaultW: 60, defaultH: 5  },
];

/* ── Client-side QR generation (per-instance cache; URL depends on props.buildUrl) ── */
const qrCache = reactive({});
async function getQrDataUrl(idCode, size = 200) {
    const key = `${idCode}_${size}`;
    if (qrCache[key]) return qrCache[key];
    const url = props.buildUrl(idCode);
    const dataUrl = await QRCode.toDataURL(url, {
        width: size, margin: 1,
        color: { dark: '#000000', light: '#ffffff' },
        errorCorrectionLevel: 'H',
    });
    qrCache[key] = dataUrl;
    return dataUrl;
}

/* Design elements on canvas */
let nextElemId = 1;
const elements = ref([]);
const selectedElemId = ref(null);
const selectedElem = computed(() => elements.value.find(e => e.id === selectedElemId.value) || null);

function makeElement(key) {
    const field = designerFields.find(f => f.key === key) || { defaultW: 40, defaultH: 5 };
    return {
        id:         nextElemId++,
        key,
        x:          5,
        y:          5,
        w:          field.defaultW,
        h:          field.defaultH,
        fontSize:   key === 'qr' ? 0 : (key === 'id_code' ? 9 : 7),
        fontWeight: key === 'id_code' ? 'bold' : 'normal',
        color:      key === 'id_code' ? '#1d4ed8' : '#1e293b',
        align:      'center',
        customText: key === '__text__' ? 'ข้อความ' : '',
        showLabel:  false,
    };
}

function addElement(key) {
    if (key === 'qr' && elements.value.find(e => e.key === 'qr')) {
        Swal.fire({ icon: 'info', title: 'มี QR Code อยู่แล้ว', timer: 1200, showConfirmButton: false });
        return;
    }
    const el = makeElement(key);
    el.x = 5 + (elements.value.length % 5) * 2;
    el.y = 5 + (elements.value.length % 5) * 2;
    elements.value.push(el);
    selectedElemId.value = el.id;
}

function removeElement(id) {
    elements.value = elements.value.filter(e => e.id !== id);
    if (selectedElemId.value === id) selectedElemId.value = null;
}

function selectElement(id) { selectedElemId.value = id; }

function loadDefaultLayout() {
    elements.value = [];
    nextElemId = 1;

    const qr = makeElement('qr');
    qr.x = 3; qr.y = 3; qr.w = 28; qr.h = 28;
    elements.value.push(qr);

    const id = makeElement('id_code');
    id.x = 33; id.y = 3; id.w = 54; id.h = 7; id.fontSize = 10; id.fontWeight = 'bold';
    elements.value.push(id);

    const nm = makeElement('name_th');
    nm.x = 33; nm.y = 12; nm.w = 54; nm.h = 6;
    elements.value.push(nm);

    const mf = makeElement('manufacturer');
    mf.x = 33; mf.y = 20; mf.w = 27; mf.h = 5; mf.fontSize = 6.5;
    elements.value.push(mf);

    const md = makeElement('model');
    md.x = 62; md.y = 20; md.w = 25; md.h = 5; md.fontSize = 6.5;
    elements.value.push(md);

    const dep = makeElement('department');
    dep.x = 3; dep.y = 33; dep.w = 84; dep.h = 5; dep.fontSize = 6.5; dep.color = '#475569';
    elements.value.push(dep);

    const sn = makeElement('serial_number');
    sn.x = 3; sn.y = 40; sn.w = 84; sn.h = 5; sn.fontSize = 6.5; sn.color = '#475569';
    elements.value.push(sn);

    selectedElemId.value = null;
}

loadDefaultLayout();

/* ── Preview equipment for designer ── */
const designPreviewItem = ref(null);
const designPreviewQr   = ref('');

watch(() => props.items, async (list) => {
    if (list.length && !designPreviewItem.value) {
        designPreviewItem.value = list[0];
        designPreviewQr.value = await getQrDataUrl(list[0].id_code, 300);
    }
}, { immediate: true });

async function setDesignPreview(item) {
    designPreviewItem.value = item;
    designPreviewQr.value   = await getQrDataUrl(item.id_code, 300);
}

function getItemFieldValue(el, item) {
    const map = {
        id_code:       item?.id_code       || '',
        name_th:       item?.name_th       || '',
        name_en:       item?.name_en       || '',
        manufacturer:  item?.manufacturer  || '',
        model:         item?.model         || '',
        serial_number: item?.serial_number || '',
        department:    item?.department?.name_th || '',
        fiscal_year:   item?.fiscal_year   || '',
        __text__:      el.customText       || '',
        __hospital__:  'โรงพยาบาลเชียงกลาง',
    };
    return map[el.key] ?? '';
}

function getElementDisplayValue(el) {
    const it = designPreviewItem.value;
    if (!it) {
        if (el.key === 'qr')           return '';
        if (el.key === '__text__')      return el.customText;
        if (el.key === '__hospital__')  return 'โรงพยาบาลเชียงกลาง';
        const field = designerFields.find(f => f.key === el.key);
        return field ? `[${field.label}]` : '';
    }
    return getItemFieldValue(el, it);
}

/* ── Canvas drag ── */
const canvasRef = ref(null);
const dragging  = ref(null);

function onElemMouseDown(e, el) {
    e.preventDefault();
    e.stopPropagation();
    selectElement(el.id);
    dragging.value = { id: el.id, startX: e.clientX, startY: e.clientY, origX: el.x, origY: el.y };
}

function onCanvasMouseMove(e) {
    if (!dragging.value) return;
    const d = dragging.value;
    const dx = (e.clientX - d.startX) / SCALE;
    const dy = (e.clientY - d.startY) / SCALE;
    const el = elements.value.find(el => el.id === d.id);
    if (!el) return;
    el.x = Math.max(0, Math.min(labelSize.w - el.w, d.origX + dx));
    el.y = Math.max(0, Math.min(labelSize.h - el.h, d.origY + dy));
}

function onCanvasMouseUp() { dragging.value = null; }

onUnmounted(() => {
    window.removeEventListener('mousemove', onCanvasMouseMove);
    window.removeEventListener('mouseup', onCanvasMouseUp);
});

/* Palette drag-to-canvas */
const paletteDragging = ref(null);

function onPaletteDragStart(e, key) {
    paletteDragging.value = key;
    e.dataTransfer.effectAllowed = 'copy';
}

function onCanvasDrop(e) {
    e.preventDefault();
    if (!paletteDragging.value) return;
    const key = paletteDragging.value;
    paletteDragging.value = null;

    if (key === 'qr' && elements.value.find(el => el.key === 'qr')) {
        Swal.fire({ icon: 'info', title: 'มี QR Code อยู่แล้ว', timer: 1200, showConfirmButton: false });
        return;
    }

    const rect  = canvasRef.value.getBoundingClientRect();
    const field = designerFields.find(f => f.key === key) || { defaultW: 40, defaultH: 5 };
    const xMm   = (e.clientX - rect.left) / SCALE - field.defaultW / 2;
    const yMm   = (e.clientY - rect.top)  / SCALE - field.defaultH / 2;

    const el = makeElement(key);
    el.x = Math.max(0, Math.min(labelSize.w - el.w, xMm));
    el.y = Math.max(0, Math.min(labelSize.h - el.h, yMm));
    elements.value.push(el);
    selectedElemId.value = el.id;
}

function onCanvasDragOver(e) { e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; }

/* ── Export designer PDF ── */
const exportingDesign = ref(false);
const designPaperSize = ref('a4');
const designSelected  = ref(new Set());
const designPrintItems = computed(() => props.items.filter((it) => designSelected.value.has(it.id)));

function toggleDesignSelect(id) {
    const s = new Set(designSelected.value);
    s.has(id) ? s.delete(id) : s.add(id);
    designSelected.value = s;
}

function buildDesignLabelHtml(it, qUrl) {
    const px = 3.78;
    const lw = labelSize.w * px;
    const lh = labelSize.h * px;

    const elems = elements.value.map(el => {
        const left = el.x * px, top = el.y * px, w = el.w * px, h = el.h * px;
        if (el.key === 'qr') {
            return `<img src="${qUrl}" style="position:absolute;left:${left}px;top:${top}px;width:${w}px;height:${h}px;object-fit:contain;" />`;
        }
        const val = getItemFieldValue(el, it);
        return `<div style="
            position:absolute;left:${left}px;top:${top}px;width:${w}px;height:${h}px;
            font-size:${(el.fontSize || 7) * 1.33}px;
            font-weight:${el.fontWeight || 'normal'};
            color:${el.color || '#1e293b'};
            text-align:${el.align || 'left'};
            font-family:sans-serif;
            overflow:hidden;white-space:nowrap;text-overflow:ellipsis;
            display:flex;align-items:center;
            ${el.align === 'center' ? 'justify-content:center;' : el.align === 'right' ? 'justify-content:flex-end;' : ''}
        ">${val}</div>`;
    }).join('');

    return `<div style="
        position:relative;width:${lw}px;height:${lh}px;
        background:#fff;border:1px dashed #94a3b8;border-radius:4px;
        overflow:hidden;box-sizing:border-box;
    ">${elems}</div>`;
}

async function exportDesignPdf() {
    if (designSelected.value.size === 0) {
        Swal.fire({ icon: 'warning', title: 'เลือกเครื่องมืออย่างน้อย 1 รายการก่อน Export' });
        return;
    }
    exportingDesign.value = true;

    const container = document.createElement('div');
    container.style.cssText = 'position:fixed;left:-9999px;top:0;z-index:-1;pointer-events:none;';
    document.body.appendChild(container);

    try {
        const paperMap = { a4: [210, 297], a5: [148, 210], letter: [215.9, 279.4] };
        const [pw, ph] = paperMap[designPaperSize.value] ?? [210, 297];
        const pdf      = new jsPDF({ orientation: 'portrait', unit: 'mm', format: [pw, ph] });

        const margin = 8, gap = 4;
        const lw = labelSize.w, lh = labelSize.h;
        const cols = Math.max(1, Math.floor((pw - margin * 2 + gap) / (lw + gap)));

        let col = 0, curRow = 0, firstPage = true;

        for (const it of designPrintItems.value) {
            const qUrl = await getQrDataUrl(it.id_code, 300);

            if (!firstPage) {
                const cy = margin + curRow * (lh + gap);
                if (cy + lh > ph - margin) { pdf.addPage(); col = 0; curRow = 0; }
            }
            firstPage = false;

            const fcx = margin + col * (lw + gap);
            const fcy = margin + curRow * (lh + gap);

            const wrapper = document.createElement('div');
            wrapper.innerHTML = buildDesignLabelHtml(it, qUrl);
            container.appendChild(wrapper);

            const canvas = await html2canvas(wrapper.firstElementChild, {
                scale: 2, useCORS: true, logging: false, backgroundColor: '#ffffff',
            });
            const imgData = canvas.toDataURL('image/png');
            pdf.addImage(imgData, 'PNG', fcx, fcy, lw, lh);
            container.removeChild(wrapper);

            col++;
            if (col >= cols) { col = 0; curRow++; }
        }

        pdf.save(`${props.filePrefix}_${new Date().toISOString().slice(0, 10)}.pdf`);
        Swal.fire({ icon: 'success', title: 'Export สำเร็จ', timer: 1500, showConfirmButton: false });
    } catch (err) {
        console.error(err);
        Swal.fire({ icon: 'error', title: 'Export ไม่สำเร็จ', text: String(err) });
    } finally {
        document.body.removeChild(container);
        exportingDesign.value = false;
    }
}
</script>

<template>
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-4">

        <!-- LEFT: Field Palette -->
        <div class="xl:col-span-2 space-y-3">
            <div class="card-base p-3">
                <div class="text-xs font-semibold text-slate-700 mb-3 flex items-center gap-1.5">
                    <DocumentTextIcon class="w-4 h-4 text-violet-500" /> Fields
                </div>
                <div class="space-y-1">
                    <div v-for="f in designerFields" :key="f.key"
                        draggable="true"
                        @dragstart="onPaletteDragStart($event, f.key)"
                        @click="addElement(f.key)"
                        class="flex items-center gap-2 px-2.5 py-2 rounded-lg border border-slate-200 text-xs text-slate-700 cursor-grab hover:bg-violet-50 hover:border-violet-300 hover:text-violet-700 transition select-none">
                        <span>{{ f.icon }}</span>
                        <span class="truncate">{{ f.label }}</span>
                        <PlusIcon class="w-3 h-3 ml-auto shrink-0 text-slate-400" />
                    </div>
                </div>
                <div class="mt-3 pt-3 border-t border-slate-100">
                    <button @click="loadDefaultLayout" class="w-full px-2 py-1.5 rounded-lg text-xs text-slate-600 hover:bg-slate-100 transition">
                        ↺ รีเซ็ตเป็นค่าเริ่มต้น
                    </button>
                </div>
            </div>

            <!-- Label size -->
            <div class="card-base p-3 space-y-2">
                <div class="text-xs font-semibold text-slate-700 mb-1">ขนาดป้าย (mm)</div>
                <div>
                    <label class="text-[11px] text-slate-500">กว้าง: <b>{{ labelSize.w }}</b> mm</label>
                    <input v-model.number="labelSize.w" type="range" min="50" max="200" step="5" class="w-full accent-violet-600 mt-1" />
                </div>
                <div>
                    <label class="text-[11px] text-slate-500">สูง: <b>{{ labelSize.h }}</b> mm</label>
                    <input v-model.number="labelSize.h" type="range" min="30" max="150" step="5" class="w-full accent-violet-600 mt-1" />
                </div>
            </div>
        </div>

        <!-- CENTER: Canvas + Preview selector -->
        <div class="xl:col-span-7 space-y-3">
            <div v-if="hint" class="card-base px-4 py-2.5 text-xs text-slate-600 flex items-center gap-2">
                <PaintBrushIcon class="w-4 h-4 text-rose-500 shrink-0" /> {{ hint }}
            </div>

            <!-- Preview item selector -->
            <div class="card-base p-3 flex items-center gap-3 flex-wrap">
                <span class="text-xs text-slate-500 shrink-0">Preview ด้วยข้อมูล:</span>
                <select @change="(e) => { const it = items.find(i => i.id === +e.target.value); if(it) setDesignPreview(it); }"
                    class="flex-1 min-w-0 px-3 py-1.5 rounded-xl border border-slate-200 text-sm bg-white">
                    <option value="">— เลือกเครื่องมือ —</option>
                    <option v-for="it in items" :key="it.id" :value="it.id">{{ it.id_code }} — {{ it.name_th }}</option>
                </select>
                <span v-if="designPreviewItem" class="text-xs text-emerald-600 shrink-0">✓ {{ designPreviewItem.id_code }}</span>
            </div>

            <!-- Canvas -->
            <div class="card-base p-4">
                <div class="text-xs text-slate-500 mb-3 flex items-center gap-2">
                    <ArrowsPointingOutIcon class="w-4 h-4" />
                    ลากจาก Field Palette หรือคลิก → วางบนพื้นที่ด้านล่าง
                    <span class="ml-auto font-mono text-slate-400">{{ labelSize.w }} × {{ labelSize.h }} mm</span>
                </div>

                <div class="overflow-auto">
                    <div
                        ref="canvasRef"
                        class="relative bg-white border-2 border-slate-300 rounded-lg select-none"
                        :style="{
                            width:  labelSize.w * SCALE + 'px',
                            height: labelSize.h * SCALE + 'px',
                            cursor: dragging ? 'grabbing' : 'default',
                        }"
                        @mousemove="onCanvasMouseMove"
                        @mouseup="onCanvasMouseUp"
                        @mouseleave="onCanvasMouseUp"
                        @dragover="onCanvasDragOver"
                        @drop="onCanvasDrop"
                        @click.self="selectedElemId = null"
                    >
                        <svg class="absolute inset-0 w-full h-full pointer-events-none opacity-20" xmlns="http://www.w3.org/2000/svg">
                            <defs>
                                <pattern id="grid" :width="SCALE * 5" :height="SCALE * 5" patternUnits="userSpaceOnUse">
                                    <path :d="`M ${SCALE * 5} 0 L 0 0 0 ${SCALE * 5}`" fill="none" stroke="#94a3b8" stroke-width="0.5"/>
                                </pattern>
                            </defs>
                            <rect width="100%" height="100%" fill="url(#grid)" />
                        </svg>

                        <div
                            v-for="el in elements"
                            :key="el.id"
                            class="absolute overflow-hidden rounded"
                            :style="{
                                left:   el.x * SCALE + 'px',
                                top:    el.y * SCALE + 'px',
                                width:  el.w * SCALE + 'px',
                                height: el.h * SCALE + 'px',
                                outline: selectedElemId === el.id ? '2px solid #6d28d9' : '1px dashed #cbd5e1',
                                cursor: 'grab',
                                zIndex: selectedElemId === el.id ? 10 : 1,
                            }"
                            @mousedown="onElemMouseDown($event, el)"
                        >
                            <img v-if="el.key === 'qr' && designPreviewQr"
                                :src="designPreviewQr"
                                class="w-full h-full object-contain pointer-events-none"
                                draggable="false" />
                            <div v-else-if="el.key === 'qr'"
                                class="w-full h-full flex items-center justify-center bg-slate-100 pointer-events-none">
                                <QrCodeIcon class="w-6 h-6 text-slate-400" />
                            </div>

                            <div v-else
                                class="w-full h-full flex items-center overflow-hidden pointer-events-none px-0.5"
                                :style="{
                                    fontSize:   (el.fontSize || 7) * SCALE * 0.35 + 'px',
                                    fontWeight: el.fontWeight,
                                    color:      el.color,
                                    textAlign:  el.align,
                                    justifyContent: el.align === 'center' ? 'center' : el.align === 'right' ? 'flex-end' : 'flex-start',
                                }">
                                <span class="truncate w-full">{{ getElementDisplayValue(el) || `[${designerFields.find(f=>f.key===el.key)?.label || el.key}]` }}</span>
                            </div>

                            <div v-if="selectedElemId === el.id"
                                class="absolute inset-0 ring-2 ring-violet-500 ring-offset-0 pointer-events-none rounded">
                                <button
                                    @mousedown.stop
                                    @click.stop="removeElement(el.id)"
                                    class="absolute -top-3 -right-3 w-5 h-5 bg-rose-500 text-white rounded-full flex items-center justify-center pointer-events-auto hover:bg-rose-600 shadow"
                                    title="ลบ">
                                    <XMarkIcon class="w-3 h-3" />
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- RIGHT: Properties panel + Export -->
        <div class="xl:col-span-3 space-y-3">

            <!-- Element Properties -->
            <div class="card-base p-4 space-y-3">
                <div class="text-xs font-semibold text-slate-700 flex items-center gap-1.5">
                    <AdjustmentsHorizontalIcon class="w-4 h-4 text-violet-500" />
                    {{ selectedElem ? 'คุณสมบัติ Element' : 'เลือก Element' }}
                </div>

                <div v-if="!selectedElem" class="py-6 text-center text-xs text-slate-400">
                    คลิก Element บน Canvas เพื่อแก้ไข
                </div>

                <template v-else>
                    <div class="text-xs font-medium text-violet-700 bg-violet-50 px-2 py-1 rounded-lg">
                        {{ designerFields.find(f => f.key === selectedElem.key)?.label || selectedElem.key }}
                    </div>

                    <div v-if="selectedElem.key === '__text__'">
                        <label class="text-[11px] text-slate-500 block mb-1">ข้อความ</label>
                        <input v-model="selectedElem.customText" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-xs outline-none focus:border-violet-400" />
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <label class="text-[11px] text-slate-500 block mb-1">X (mm)</label>
                            <input v-model.number="selectedElem.x" type="number" min="0" :max="labelSize.w - selectedElem.w"
                                class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-xs outline-none focus:border-violet-400" />
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-500 block mb-1">Y (mm)</label>
                            <input v-model.number="selectedElem.y" type="number" min="0" :max="labelSize.h - selectedElem.h"
                                class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-xs outline-none focus:border-violet-400" />
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-500 block mb-1">กว้าง (mm)</label>
                            <input v-model.number="selectedElem.w" type="number" min="5" :max="labelSize.w"
                                class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-xs outline-none focus:border-violet-400" />
                        </div>
                        <div>
                            <label class="text-[11px] text-slate-500 block mb-1">สูง (mm)</label>
                            <input v-model.number="selectedElem.h" type="number" min="3" :max="labelSize.h"
                                class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-xs outline-none focus:border-violet-400" />
                        </div>
                    </div>

                    <template v-if="selectedElem.key !== 'qr'">
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-[11px] text-slate-500 block mb-1">ขนาด Font (pt)</label>
                                <input v-model.number="selectedElem.fontSize" type="number" min="5" max="30"
                                    class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-xs outline-none focus:border-violet-400" />
                            </div>
                            <div>
                                <label class="text-[11px] text-slate-500 block mb-1">สี</label>
                                <input v-model="selectedElem.color" type="color"
                                    class="w-full h-8 rounded-lg border border-slate-200 cursor-pointer p-0.5" />
                            </div>
                        </div>
                        <div class="grid grid-cols-2 gap-2">
                            <div>
                                <label class="text-[11px] text-slate-500 block mb-1">น้ำหนัก</label>
                                <select v-model="selectedElem.fontWeight" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-xs bg-white">
                                    <option value="normal">Normal</option>
                                    <option value="bold">Bold</option>
                                </select>
                            </div>
                            <div>
                                <label class="text-[11px] text-slate-500 block mb-1">จัดข้อความ</label>
                                <select v-model="selectedElem.align" class="w-full px-2 py-1.5 rounded-lg border border-slate-200 text-xs bg-white">
                                    <option value="left">ซ้าย</option>
                                    <option value="center">กลาง</option>
                                    <option value="right">ขวา</option>
                                </select>
                            </div>
                        </div>
                    </template>

                    <button @click="removeElement(selectedElem.id)"
                        class="w-full px-3 py-2 rounded-xl bg-rose-50 text-rose-600 text-xs font-medium hover:bg-rose-100 transition flex items-center justify-center gap-1.5">
                        <TrashIcon class="w-3.5 h-3.5" /> ลบ Element นี้
                    </button>
                </template>
            </div>

            <!-- Export -->
            <div class="card-base p-4 space-y-3">
                <div class="text-xs font-semibold text-slate-700 flex items-center gap-1.5">
                    <PrinterIcon class="w-4 h-4 text-violet-500" /> Export PDF
                </div>

                <div>
                    <label class="text-[11px] text-slate-500 block mb-1">ขนาดกระดาษ</label>
                    <select v-model="designPaperSize" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm bg-white">
                        <option value="a4">A4</option>
                        <option value="a5">A5</option>
                        <option value="letter">Letter</option>
                    </select>
                </div>

                <div class="text-[11px] text-slate-500">เลือกเครื่องมือที่ต้องการพิมพ์:</div>
                <div class="border border-slate-200 rounded-xl overflow-hidden max-h-[200px] overflow-y-auto">
                    <div v-if="!items.length" class="p-4 text-center text-xs text-slate-400">ไม่มีข้อมูล</div>
                    <label v-for="it in items" :key="it.id"
                        class="flex items-center gap-2 px-3 py-2 hover:bg-slate-50 cursor-pointer border-b border-slate-100 last:border-0">
                        <input type="checkbox" :checked="designSelected.has(it.id)" @change="toggleDesignSelect(it.id)" class="w-3.5 h-3.5 accent-violet-600" />
                        <span class="font-mono text-[10px] text-blue-700">{{ it.id_code }}</span>
                        <span class="text-[10px] text-slate-600 truncate">{{ it.name_th }}</span>
                    </label>
                </div>

                <div class="text-[11px] text-slate-500">
                    เลือกแล้ว <b class="text-violet-600">{{ designSelected.size }}</b> รายการ
                </div>

                <button @click="exportDesignPdf" :disabled="designSelected.size === 0 || exportingDesign"
                    :class="['w-full inline-flex items-center justify-center gap-2 px-4 py-2.5 rounded-xl bg-gradient-to-r text-white text-sm font-semibold shadow disabled:opacity-50 transition', accentGradient]">
                    <svg v-if="exportingDesign" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    <PrinterIcon v-else class="w-4 h-4" />
                    {{ exportingDesign ? 'กำลัง Export...' : 'Export PDF' }}
                </button>
            </div>
        </div>
    </div>
</template>
