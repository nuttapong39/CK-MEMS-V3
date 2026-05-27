<script setup>
import { ref, watch, computed } from 'vue';
import BaseModal from '../common/BaseModal.vue';
import { equipmentsApi } from '../../api/equipments';
import { useAuthStore }   from '../../stores/auth';
import { useMasterStore } from '../../stores/master';
import {
    PencilSquareIcon, CheckIcon, XMarkIcon,
    BuildingOffice2Icon, IdentificationIcon, WrenchIcon,
    BeakerIcon, CalendarDaysIcon, DocumentTextIcon,
    CameraIcon, PhotoIcon, TrashIcon,
} from '@heroicons/vue/24/outline';
import Swal from 'sweetalert2';

const props = defineProps({
    open:        { type: Boolean, default: false },
    equipmentId: { type: [Number, String], default: null },
});
const emit = defineEmits(['close', 'saved']);

const auth    = useAuthStore();
const master  = useMasterStore();

const loading    = ref(false);
const saving     = ref(false);
const editMode   = ref(false);
const equipment  = ref(null);

// Image upload state
const imageFile      = ref(null);
const imagePreview   = ref(null);
const removeImageFlag = ref(false);
const imageInput     = ref(null);
const cameraInput    = ref(null);
const uploadingImage = ref(false);

function onImageSelected(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    imageFile.value    = file;
    imagePreview.value = URL.createObjectURL(file);
    removeImageFlag.value = false;
    e.target.value = '';
}

function clearNewImage() {
    imageFile.value    = null;
    imagePreview.value = null;
}

function flagRemoveImage() {
    removeImageFlag.value = true;
    imageFile.value    = null;
    imagePreview.value = null;
}

const canEdit = computed(() => auth.hasAnyRole(['admin', 'staff']));

// ── Edit form ─────────────────────────────────────────────────────
const form = ref({});

const statusOptions = [
    { value: 'ACTIVE',           label: 'ใช้งานอยู่',       cls: 'bg-emerald-100 text-emerald-700' },
    { value: 'BROKEN',           label: 'ชำรุด',             cls: 'bg-rose-100 text-rose-700' },
    { value: 'UNDER_REPAIR',     label: 'กำลังซ่อม',         cls: 'bg-amber-100 text-amber-700' },
    { value: 'RETIRED',          label: 'จำหน่ายแล้ว',       cls: 'bg-slate-100 text-slate-600' },
    { value: 'PENDING_DISPOSAL', label: 'รอแทงจำหน่าย',     cls: 'bg-indigo-100 text-indigo-700' },
];
const calibrationOptions = [
    { value: 'NONE',     label: 'ไม่ต้องสอบเทียบ' },
    { value: 'INTERNAL', label: 'ภายใน' },
    { value: 'EXTERNAL', label: 'ภายนอก' },
    { value: 'BOTH',     label: 'ทั้งภายในและภายนอก' },
];

function statusBadge(s) {
    return statusOptions.find(o => o.value === s) ?? { label: s, cls: 'bg-slate-100 text-slate-600' };
}

function calibrationLabel(v) {
    return calibrationOptions.find(o => o.value === v)?.label ?? v ?? '—';
}

// ── Load ──────────────────────────────────────────────────────────
watch(() => props.open, async (val) => {
    if (val && props.equipmentId) {
        editMode.value = false;
        loading.value  = true;
        equipment.value = null;
        try {
            await master.loadAll();
            const { data } = await equipmentsApi.show(props.equipmentId);
            equipment.value = data.data ?? data;
        } catch {
            Swal.fire({ icon: 'error', title: 'โหลดข้อมูลไม่สำเร็จ', timer: 1500, showConfirmButton: false });
            emit('close');
        } finally {
            loading.value = false;
        }
    }
});

function startEdit() {
    if (!equipment.value) return;
    form.value = {
        name_th:                      equipment.value.name_th ?? '',
        name_en:                      equipment.value.name_en ?? '',
        manufacturer:                 equipment.value.manufacturer ?? '',
        model:                        equipment.value.model ?? '',
        serial_number:                equipment.value.serial_number ?? '',
        status:                       equipment.value.status ?? 'ACTIVE',
        calibration_by:               equipment.value.calibration_by ?? 'NONE',
        maintenance_cycles_per_year:  equipment.value.maintenance_cycles_per_year ?? 1,
        note:                         equipment.value.note ?? '',
    };
    imageFile.value       = null;
    imagePreview.value    = null;
    removeImageFlag.value = false;
    editMode.value = true;
}

function cancelEdit() {
    imageFile.value       = null;
    imagePreview.value    = null;
    removeImageFlag.value = false;
    editMode.value = false;
}

async function saveEdit() {
    saving.value = true;
    try {
        const { data } = await equipmentsApi.update(equipment.value.id, form.value);
        let saved = data.data ?? data;

        // Handle image changes
        if (imageFile.value) {
            const { data: imgData } = await equipmentsApi.uploadImage(saved.id, imageFile.value);
            saved.image_url = imgData.image_url;
        } else if (removeImageFlag.value) {
            await equipmentsApi.removeImage(saved.id);
            saved.image_url = null;
        }

        equipment.value = saved;
        editMode.value  = false;
        imageFile.value       = null;
        imagePreview.value    = null;
        removeImageFlag.value = false;
        Swal.fire({ icon: 'success', title: 'บันทึกเรียบร้อย', timer: 1400, showConfirmButton: false });
        emit('saved');
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'บันทึกไม่สำเร็จ', text: e.response?.data?.message ?? '' });
    } finally {
        saving.value = false;
    }
}

function handleClose() {
    editMode.value = false;
    emit('close');
}

function formatDate(d) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('th-TH', { year: 'numeric', month: 'short', day: 'numeric' });
}
</script>

<template>
    <BaseModal
        :open="open"
        :title="editMode ? 'แก้ไขข้อมูลเครื่องมือ' : 'รายละเอียดเครื่องมือ'"
        :subtitle="equipment?.id_code ?? ''"
        size="3xl"
        @close="handleClose"
    >
        <!-- Loading -->
        <div v-if="loading" class="py-16 text-center text-slate-400">
            <div class="w-8 h-8 border-2 border-blue-500 border-t-transparent rounded-full animate-spin mx-auto mb-3"></div>
            กำลังโหลด...
        </div>

        <!-- Content -->
        <template v-else-if="equipment">

            <!-- ── VIEW MODE ────────────────────────────────────────── -->
            <div v-if="!editMode" class="space-y-5">

                <!-- Equipment Image -->
                <div v-if="equipment.image_url" class="w-full rounded-2xl overflow-hidden border border-slate-100 bg-slate-50">
                    <img :src="equipment.image_url" :alt="equipment.name_th" class="w-full max-h-56 object-contain" />
                </div>

                <!-- ID Code banner -->
                <div class="flex flex-col sm:flex-row sm:items-center gap-3 p-4 rounded-2xl bg-blue-50 border border-blue-100">
                    <div class="flex-1 min-w-0">
                        <div class="text-xs text-blue-500 font-semibold uppercase tracking-wider mb-0.5">รหัสเครื่องมือ</div>
                        <div class="text-2xl font-mono font-bold text-blue-700 tracking-wider">{{ equipment.id_code }}</div>
                    </div>
                    <span :class="['text-sm px-3 py-1.5 rounded-full font-semibold shrink-0', statusBadge(equipment.status).cls]">
                        {{ statusBadge(equipment.status).label }}
                    </span>
                </div>

                <!-- Name -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide">ชื่อ (ไทย)</div>
                        <div class="text-base font-semibold text-slate-800">{{ equipment.name_th || '—' }}</div>
                    </div>
                    <div class="space-y-1">
                        <div class="text-xs font-semibold text-slate-500 uppercase tracking-wide">ชื่อ (อังกฤษ)</div>
                        <div class="text-base text-slate-700">{{ equipment.name_en || '—' }}</div>
                    </div>
                </div>

                <div class="border-t border-slate-100"></div>

                <!-- Details grid -->
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <div class="text-xs text-slate-400 mb-0.5 flex items-center gap-1">
                            <BuildingOffice2Icon class="w-3.5 h-3.5" /> หน่วยงาน
                        </div>
                        <div class="font-medium text-slate-700">
                            {{ equipment.department?.name_th || '—' }}
                            <span v-if="equipment.department?.code" class="text-xs text-slate-400 ml-1">({{ equipment.department.code }})</span>
                        </div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-400 mb-0.5">ยี่ห้อ</div>
                        <div class="font-medium text-slate-700">{{ equipment.manufacturer || '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-400 mb-0.5">รุ่น</div>
                        <div class="font-medium text-slate-700">{{ equipment.model || '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-400 mb-0.5 flex items-center gap-1">
                            <IdentificationIcon class="w-3.5 h-3.5" /> Serial Number
                        </div>
                        <div class="font-mono text-xs font-semibold text-slate-700">{{ equipment.serial_number || '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-400 mb-0.5">หมายเลขครุภัณฑ์</div>
                        <div class="font-medium text-slate-700">{{ equipment.asset_number || '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-400 mb-0.5 flex items-center gap-1">
                            <CalendarDaysIcon class="w-3.5 h-3.5" /> ปีงบประมาณ
                        </div>
                        <div class="font-medium text-slate-700">{{ equipment.fiscal_year ? `พ.ศ. ${equipment.fiscal_year}` : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-400 mb-0.5 flex items-center gap-1">
                            <BeakerIcon class="w-3.5 h-3.5" /> สอบเทียบโดย
                        </div>
                        <div class="font-medium text-slate-700">{{ calibrationLabel(equipment.calibration_by) }}</div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-400 mb-0.5 flex items-center gap-1">
                            <WrenchIcon class="w-3.5 h-3.5" /> รอบซ่อมบำรุง/ปี
                        </div>
                        <div class="font-medium text-slate-700">{{ equipment.maintenance_cycles_per_year ?? '—' }} ครั้ง/ปี</div>
                    </div>
                    <div>
                        <div class="text-xs text-slate-400 mb-0.5">วันที่ซื้อ</div>
                        <div class="font-medium text-slate-700">{{ formatDate(equipment.purchase_date) }}</div>
                    </div>
                </div>

                <!-- Latest calibration -->
                <div v-if="equipment.latest_calibration" class="rounded-xl bg-emerald-50 border border-emerald-100 p-4">
                    <div class="text-xs font-semibold text-emerald-700 mb-2 uppercase tracking-wide">การสอบเทียบล่าสุด</div>
                    <div class="grid grid-cols-3 gap-3 text-sm">
                        <div>
                            <div class="text-xs text-emerald-600">วันที่สอบเทียบ</div>
                            <div class="font-medium text-emerald-800">{{ formatDate(equipment.latest_calibration.calibrated_at) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-emerald-600">สอบเทียบครั้งถัดไป</div>
                            <div class="font-medium text-emerald-800">{{ formatDate(equipment.latest_calibration.next_due_at) }}</div>
                        </div>
                        <div>
                            <div class="text-xs text-emerald-600">ผลการสอบเทียบ</div>
                            <div class="font-medium text-emerald-800">{{ equipment.latest_calibration.result || '—' }}</div>
                        </div>
                    </div>
                </div>

                <!-- Note -->
                <div v-if="equipment.note">
                    <div class="text-xs text-slate-400 mb-1 flex items-center gap-1">
                        <DocumentTextIcon class="w-3.5 h-3.5" /> หมายเหตุ
                    </div>
                    <p class="text-sm text-slate-600 bg-slate-50 rounded-xl p-3 border border-slate-100 whitespace-pre-wrap">{{ equipment.note }}</p>
                </div>
            </div>

            <!-- ── EDIT MODE ─────────────────────────────────────────── -->
            <form v-else @submit.prevent="saveEdit" class="space-y-4">

                <!-- Status -->
                <div>
                    <label class="text-xs font-semibold text-slate-600 block mb-1.5">สถานะ</label>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="opt in statusOptions" :key="opt.value"
                            type="button"
                            @click="form.status = opt.value"
                            :class="[
                                'px-3 py-1.5 rounded-full text-xs font-semibold border-2 transition',
                                form.status === opt.value
                                    ? [opt.cls, 'border-current shadow-sm']
                                    : 'border-slate-200 text-slate-500 hover:border-slate-300'
                            ]"
                        >{{ opt.label }}</button>
                    </div>
                </div>

                <!-- Names -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1.5">ชื่อ (ไทย) <span class="text-rose-500">*</span></label>
                        <input v-model="form.name_th" required class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1.5">ชื่อ (อังกฤษ)</label>
                        <input v-model="form.name_en" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none" />
                    </div>
                </div>

                <!-- Manufacturer / Model / SN -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1.5">ยี่ห้อ</label>
                        <input v-model="form.manufacturer" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:border-blue-500 outline-none" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1.5">รุ่น</label>
                        <input v-model="form.model" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:border-blue-500 outline-none" />
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1.5">Serial Number</label>
                        <input v-model="form.serial_number" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm font-mono focus:border-blue-500 outline-none" />
                    </div>
                </div>

                <!-- Calibration / Maintenance -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1.5">การสอบเทียบ</label>
                        <select v-model="form.calibration_by" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm bg-white focus:border-blue-500 outline-none">
                            <option v-for="o in calibrationOptions" :key="o.value" :value="o.value">{{ o.label }}</option>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs font-semibold text-slate-600 block mb-1.5">รอบซ่อมบำรุง (ครั้ง/ปี)</label>
                        <input v-model.number="form.maintenance_cycles_per_year" type="number" min="0" max="52"
                            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:border-blue-500 outline-none" />
                    </div>
                </div>

                <!-- Note -->
                <div>
                    <label class="text-xs font-semibold text-slate-600 block mb-1.5">หมายเหตุ</label>
                    <textarea v-model="form.note" rows="3"
                        class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:border-blue-500 outline-none resize-none"
                        placeholder="บันทึกเพิ่มเติม..."
                    ></textarea>
                </div>

                <!-- Image upload -->
                <div class="border border-slate-100 rounded-2xl p-4 space-y-3">
                    <div class="text-xs font-semibold text-slate-600 flex items-center gap-1.5">
                        <PhotoIcon class="w-4 h-4 text-amber-500" /> รูปภาพเครื่องมือ
                    </div>

                    <!-- Current image or new preview -->
                    <div v-if="imagePreview || (equipment.image_url && !removeImageFlag)" class="relative w-full">
                        <img :src="imagePreview || equipment.image_url"
                            class="w-full max-h-40 object-contain rounded-xl border border-slate-100 bg-slate-50" />
                        <button type="button" @click="imagePreview ? clearNewImage() : flagRemoveImage()"
                            class="absolute top-2 right-2 w-6 h-6 rounded-full bg-rose-500 text-white flex items-center justify-center hover:bg-rose-600 shadow">
                            <XMarkIcon class="w-3.5 h-3.5" />
                        </button>
                    </div>

                    <!-- No image state -->
                    <div v-else class="flex items-center gap-2 text-xs text-slate-400 bg-slate-50 rounded-xl p-3">
                        <PhotoIcon class="w-4 h-4" />
                        <span>{{ removeImageFlag ? 'จะลบรูปภาพเมื่อบันทึก' : 'ยังไม่มีรูปภาพ' }}</span>
                    </div>

                    <!-- Buttons -->
                    <div class="flex gap-2">
                        <button type="button" @click="imageInput.click()"
                            class="flex items-center gap-1.5 px-3 py-2 rounded-xl border border-slate-200 text-xs text-slate-600 hover:bg-blue-50 hover:border-blue-300 hover:text-blue-600 transition">
                            <PhotoIcon class="w-4 h-4" /> แนบรูป
                        </button>
                        <button type="button" @click="cameraInput.click()"
                            class="flex items-center gap-1.5 px-3 py-2 rounded-xl border border-slate-200 text-xs text-slate-600 hover:bg-emerald-50 hover:border-emerald-300 hover:text-emerald-600 transition">
                            <CameraIcon class="w-4 h-4" /> ถ่ายรูป
                        </button>
                        <button v-if="equipment.image_url && !removeImageFlag && !imagePreview"
                            type="button" @click="flagRemoveImage"
                            class="flex items-center gap-1.5 px-3 py-2 rounded-xl border border-rose-200 text-xs text-rose-500 hover:bg-rose-50 transition">
                            <TrashIcon class="w-4 h-4" /> ลบรูป
                        </button>
                    </div>
                    <input ref="imageInput" type="file" accept="image/*" class="hidden" @change="onImageSelected" />
                    <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="hidden" @change="onImageSelected" />
                </div>
            </form>
        </template>

        <!-- Footer -->
        <template #footer>
            <template v-if="!editMode">
                <button @click="handleClose" class="px-4 py-2 rounded-xl text-sm text-slate-600 hover:bg-slate-100 transition">ปิด</button>
                <button
                    v-if="canEdit && equipment"
                    @click="startEdit"
                    class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-amber-500 hover:bg-amber-600 text-white text-sm font-semibold transition shadow"
                >
                    <PencilSquareIcon class="w-4 h-4" />
                    แก้ไข
                </button>
            </template>
            <template v-else>
                <button @click="cancelEdit" :disabled="saving" class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl text-sm text-slate-600 hover:bg-slate-100 transition disabled:opacity-50">
                    <XMarkIcon class="w-4 h-4" /> ยกเลิก
                </button>
                <button @click="saveEdit" :disabled="saving"
                    class="inline-flex items-center gap-2 px-5 py-2 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold transition shadow disabled:opacity-50"
                >
                    <svg v-if="saving" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <CheckIcon v-else class="w-4 h-4" />
                    {{ saving ? 'กำลังบันทึก...' : 'บันทึก' }}
                </button>
            </template>
        </template>
    </BaseModal>
</template>
