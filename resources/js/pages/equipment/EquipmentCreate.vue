<script setup>
import { computed, onMounted, ref, watch } from 'vue';
import { useRouter } from 'vue-router';
import Swal from 'sweetalert2';
import { useMasterStore } from '../../stores/master';
import { equipmentsApi } from '../../api/equipments';
import EquipmentCodePickerModal from '../../components/equipment/EquipmentCodePickerModal.vue';
import {
    MagnifyingGlassPlusIcon, BuildingOffice2Icon, CalendarIcon,
    IdentificationIcon, BookmarkIcon, WrenchIcon, BeakerIcon,
    ArrowLeftIcon, CheckIcon, CameraIcon, PhotoIcon, XMarkIcon,
} from '@heroicons/vue/24/outline';

const router = useRouter();
const master = useMasterStore();

const showCodePicker = ref(false);
const submitting = ref(false);

// Image upload
const imageFile    = ref(null);
const imagePreview = ref(null);
const imageInput   = ref(null);
const cameraInput  = ref(null);

function onImageSelected(e) {
    const file = e.target.files?.[0];
    if (!file) return;
    imageFile.value = file;
    imagePreview.value = URL.createObjectURL(file);
    e.target.value = '';
}

function clearImage() {
    imageFile.value    = null;
    imagePreview.value = null;
}

const form = ref({
    department_id: null,
    equipment_code_id: null,
    fiscal_year: 2569,
    asset_number: '',
    id_code: '',
    name_th: '',
    name_en: '',
    manufacturer: '',
    model: '',
    serial_number: '',
    maintenance_cycles_per_year: 1,
    calibration_by: 'NONE',
    note: '',
});
const selectedCode = ref(null);

const errors = ref({});

const fiscalYearOptions = computed(() => {
    const cur = new Date().getFullYear() + 543;
    return [cur - 2, cur - 1, cur, cur + 1];
});

onMounted(() => master.loadAll());

async function refreshIdCodePreview() {
    if (!form.value.department_id || !form.value.equipment_code_id) return;
    try {
        const { data } = await equipmentsApi.previewIdCode(
            form.value.department_id,
            form.value.equipment_code_id,
        );
        form.value.id_code = data.id_code;
    } catch (_) { /* ignore */ }
}

watch(() => [form.value.department_id, form.value.equipment_code_id], refreshIdCodePreview);

function pickCode(code) {
    selectedCode.value = code;
    form.value.equipment_code_id = code.id;
    form.value.name_th = form.value.name_th || code.name_th;
    form.value.name_en = form.value.name_en || code.name_en;
}

async function submit() {
    submitting.value = true;
    errors.value = {};
    try {
        const payload = { ...form.value };
        delete payload.id_code; // let server regenerate to ensure no race
        const { data } = await equipmentsApi.store(payload);
        // Upload image if selected
        if (imageFile.value) {
            try {
                await equipmentsApi.uploadImage(data.id, imageFile.value);
            } catch (_) { /* image upload failure is non-fatal */ }
        }
        await Swal.fire({
            icon: 'success',
            title: 'เพิ่มเครื่องมือเรียบร้อย',
            text: `รหัส: ${data.id_code}`,
            confirmButtonColor: '#2563eb',
            confirmButtonText: 'ตกลง',
        });
        router.push({ name: 'equipment.list' });
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
            Swal.fire({
                icon: 'warning',
                title: 'กรุณาตรวจสอบข้อมูล',
                text: 'มีฟิลด์ที่ยังไม่ถูกต้อง',
                confirmButtonColor: '#2563eb',
            });
        } else {
            Swal.fire({
                icon: 'error',
                title: 'เกิดข้อผิดพลาด',
                text: e.response?.data?.message || 'ไม่สามารถบันทึกได้',
                confirmButtonColor: '#2563eb',
            });
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <div class="max-w-4xl mx-auto space-y-6">
        <!-- Header -->
        <div class="flex items-center gap-3">
            <button
                @click="router.back()"
                class="p-2 rounded-xl hover:bg-white hover:shadow-sm transition"
            >
                <ArrowLeftIcon class="w-5 h-5 text-slate-600" />
            </button>
            <div>
                <h1 class="text-xl font-bold text-slate-800">เพิ่มเครื่องมือแพทย์</h1>
                <p class="text-xs text-slate-500 mt-0.5">กรอกข้อมูลเครื่องมือใหม่ — ระบบจะสร้างรหัส ID อัตโนมัติ</p>
            </div>
        </div>

        <form @submit.prevent="submit" class="space-y-6">
            <!-- Section 1: Group & Code -->
            <div class="card-base p-6 space-y-5">
                <div class="flex items-center gap-2 text-blue-600">
                    <BuildingOffice2Icon class="w-5 h-5" />
                    <h2 class="text-sm font-semibold text-slate-700">ข้อมูลกลุ่มงานและรหัส</h2>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <!-- Department -->
                    <div>
                        <label class="text-sm font-medium text-slate-700 block mb-1.5">
                            กลุ่มงาน <span class="text-rose-500">*</span>
                        </label>
                        <select
                            v-model.number="form.department_id"
                            required
                            class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition bg-white"
                        >
                            <option :value="null" disabled>-- เลือกกลุ่มงาน --</option>
                            <option v-for="d in master.departments" :key="d.id" :value="d.id">
                                {{ d.code }} — {{ d.name_th }}
                            </option>
                        </select>
                        <p v-if="errors.department_id" class="text-xs text-rose-500 mt-1">{{ errors.department_id[0] }}</p>
                    </div>

                    <!-- Fiscal year -->
                    <div>
                        <label class="text-sm font-medium text-slate-700 block mb-1.5">
                            ปีงบประมาณที่จัดซื้อ <span class="text-rose-500">*</span>
                        </label>
                        <select
                            v-model.number="form.fiscal_year"
                            required
                            class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition bg-white"
                        >
                            <option v-for="y in fiscalYearOptions" :key="y" :value="y">{{ y }}</option>
                        </select>
                    </div>

                    <!-- Code picker -->
                    <div class="md:col-span-2">
                        <label class="text-sm font-medium text-slate-700 block mb-1.5">
                            รหัสครุภัณฑ์ทางการแพทย์ <span class="text-rose-500">*</span>
                        </label>
                        <button
                            type="button"
                            @click="showCodePicker = true"
                            :class="[
                                'w-full flex items-center justify-between px-4 py-3 rounded-xl border transition text-left',
                                selectedCode
                                    ? 'border-blue-300 bg-blue-50/50 hover:bg-blue-50'
                                    : 'border-dashed border-slate-300 hover:border-blue-400 hover:bg-slate-50',
                            ]"
                        >
                            <div v-if="selectedCode" class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-lg bg-gradient-to-br from-blue-100 to-indigo-100 flex items-center justify-center font-bold text-blue-700 text-xs">
                                    {{ selectedCode.code }}
                                </div>
                                <div>
                                    <div class="font-medium text-sm text-slate-800">{{ selectedCode.name_th }}</div>
                                    <div class="text-xs text-slate-500">{{ selectedCode.name_en }}</div>
                                </div>
                            </div>
                            <div v-else class="flex items-center gap-2 text-slate-500">
                                <MagnifyingGlassPlusIcon class="w-5 h-5" />
                                <span class="text-sm">คลิกเพื่อเลือกรหัสเครื่องมือ</span>
                            </div>
                            <span class="text-xs text-blue-600 font-medium">{{ selectedCode ? 'เปลี่ยน' : 'เลือก' }}</span>
                        </button>
                        <p v-if="errors.equipment_code_id" class="text-xs text-rose-500 mt-1">{{ errors.equipment_code_id[0] }}</p>
                    </div>

                    <!-- ID code preview -->
                    <div class="md:col-span-2" v-if="form.id_code">
                        <label class="text-sm font-medium text-slate-700 block mb-1.5 flex items-center gap-2">
                            <IdentificationIcon class="w-4 h-4 text-blue-500" />
                            เลขเครื่อง ID Code (สร้างอัตโนมัติ)
                        </label>
                        <div class="px-4 py-3 rounded-xl bg-gradient-to-r from-blue-50 to-indigo-50 border border-blue-100 font-mono text-blue-700 font-semibold">
                            {{ form.id_code }}
                        </div>
                    </div>
                </div>
            </div>

            <!-- Section 2: Names and asset -->
            <div class="card-base p-6 space-y-5">
                <div class="flex items-center gap-2 text-emerald-600">
                    <BookmarkIcon class="w-5 h-5" />
                    <h2 class="text-sm font-semibold text-slate-700">รายละเอียดอุปกรณ์</h2>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="text-sm font-medium text-slate-700 block mb-1.5">
                            ชื่อรายการอุปกรณ์ (ภาษาไทย) <span class="text-rose-500">*</span>
                        </label>
                        <input v-model="form.name_th" type="text" required class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition" />
                        <p v-if="errors.name_th" class="text-xs text-rose-500 mt-1">{{ errors.name_th[0] }}</p>
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700 block mb-1.5">ชื่อรายการอุปกรณ์ (ภาษาอังกฤษ)</label>
                        <input v-model="form.name_en" type="text" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700 block mb-1.5">หมายเลขครุภัณฑ์</label>
                        <input v-model="form.asset_number" type="text" placeholder="เช่น 6515-069-3201/64-027-2" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700 block mb-1.5">Manufacture (ยี่ห้อ)</label>
                        <input v-model="form.manufacturer" type="text" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700 block mb-1.5">Model (รุ่น)</label>
                        <input v-model="form.model" type="text" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition" />
                    </div>
                    <div>
                        <label class="text-sm font-medium text-slate-700 block mb-1.5">Serial Number</label>
                        <input v-model="form.serial_number" type="text" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition" />
                    </div>
                </div>
            </div>

            <!-- Section 3: Maintenance & Calibration -->
            <div class="card-base p-6 space-y-5">
                <div class="flex items-center gap-2 text-violet-600">
                    <WrenchIcon class="w-5 h-5" />
                    <h2 class="text-sm font-semibold text-slate-700">บำรุงรักษาและการสอบเทียบ</h2>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                    <div>
                        <label class="text-sm font-medium text-slate-700 block mb-1.5">
                            บำรุงรักษาภายในปี (รอบ/ปี) <span class="text-rose-500">*</span>
                        </label>
                        <select v-model.number="form.maintenance_cycles_per_year" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition bg-white">
                            <option :value="1">1 รอบ/ปี</option>
                            <option :value="2">2 รอบ/ปี</option>
                            <option :value="3">3 รอบ/ปี (M / M / M)</option>
                        </select>
                    </div>

                    <div>
                        <label class="text-sm font-medium text-slate-700 block mb-1.5 flex items-center gap-2">
                            <BeakerIcon class="w-4 h-4 text-violet-500" />
                            สอบเทียบโดย
                        </label>
                        <div class="grid grid-cols-2 gap-2">
                            <label
                                v-for="opt in [
                                    { v: 'NONE', label: 'ยังไม่กำหนด' },
                                    { v: 'DSS', label: 'ศูนย์ วศ.' },
                                    { v: 'PRIVATE', label: 'เอกชน' },
                                    { v: 'BOTH', label: 'ทั้งสองหน่วย' },
                                ]"
                                :key="opt.v"
                                :class="[
                                    'flex items-center gap-2 px-3 py-2 rounded-xl border cursor-pointer text-sm transition',
                                    form.calibration_by === opt.v
                                        ? 'border-blue-500 bg-blue-50 text-blue-700 font-medium'
                                        : 'border-slate-200 hover:border-blue-300',
                                ]"
                            >
                                <input v-model="form.calibration_by" type="radio" :value="opt.v" class="sr-only" />
                                {{ opt.label }}
                            </label>
                        </div>
                    </div>
                </div>

                <div>
                    <label class="text-sm font-medium text-slate-700 block mb-1.5">หมายเหตุ</label>
                    <textarea v-model="form.note" rows="3" placeholder="เพิ่มข้อมูลที่เป็นประโยชน์ เช่น รับเข้าจากหน่วยงานใด" class="w-full px-4 py-2.5 rounded-xl border border-slate-200 focus:border-blue-500 focus:ring-2 focus:ring-blue-100 outline-none transition resize-none"></textarea>
                </div>
            </div>

            <!-- Section 4: รูปภาพเครื่องมือ -->
            <div class="card-base p-6 space-y-4">
                <div class="flex items-center gap-2 text-amber-600">
                    <PhotoIcon class="w-5 h-5" />
                    <h2 class="text-sm font-semibold text-slate-700">รูปภาพเครื่องมือ</h2>
                    <span class="text-xs text-slate-400">(ไม่บังคับ)</span>
                </div>

                <!-- Preview -->
                <div v-if="imagePreview" class="relative w-full max-w-xs mx-auto">
                    <img :src="imagePreview" class="w-full h-48 object-contain rounded-2xl border border-slate-200 bg-slate-50" />
                    <button
                        type="button"
                        @click="clearImage"
                        class="absolute top-2 right-2 w-7 h-7 rounded-full bg-rose-500 text-white flex items-center justify-center hover:bg-rose-600 shadow transition"
                    >
                        <XMarkIcon class="w-4 h-4" />
                    </button>
                </div>

                <!-- Upload buttons -->
                <div class="flex flex-wrap gap-3">
                    <!-- File from device -->
                    <button
                        type="button"
                        @click="imageInput.click()"
                        class="flex items-center gap-2 px-4 py-2.5 rounded-xl border-2 border-dashed border-slate-300 hover:border-blue-400 hover:bg-blue-50 text-slate-600 hover:text-blue-600 text-sm font-medium transition"
                    >
                        <PhotoIcon class="w-5 h-5" />
                        แนบรูปจากอุปกรณ์
                    </button>

                    <!-- Camera capture (mobile-friendly) -->
                    <button
                        type="button"
                        @click="cameraInput.click()"
                        class="flex items-center gap-2 px-4 py-2.5 rounded-xl border-2 border-dashed border-slate-300 hover:border-emerald-400 hover:bg-emerald-50 text-slate-600 hover:text-emerald-600 text-sm font-medium transition"
                    >
                        <CameraIcon class="w-5 h-5" />
                        ถ่ายรูป
                    </button>
                </div>

                <p class="text-xs text-slate-400">รองรับ JPG, PNG, WebP ขนาดไม่เกิน 5 MB</p>

                <!-- Hidden inputs -->
                <input ref="imageInput" type="file" accept="image/*" class="hidden" @change="onImageSelected" />
                <input ref="cameraInput" type="file" accept="image/*" capture="environment" class="hidden" @change="onImageSelected" />
            </div>

            <!-- Actions -->
            <div class="flex items-center justify-between gap-3 sticky bottom-0 -mx-2 px-2 py-3 bg-slate-50/80 backdrop-blur rounded-2xl">
                <button type="button" @click="router.push({ name: 'equipment.list' })" class="px-5 py-2.5 rounded-xl text-sm text-slate-600 hover:bg-slate-100 transition">
                    ยกเลิก
                </button>
                <button
                    type="submit"
                    :disabled="submitting || !form.department_id || !form.equipment_code_id"
                    class="flex items-center gap-2 px-6 py-2.5 rounded-xl bg-gradient-to-r from-blue-600 to-indigo-600 text-white font-semibold shadow-lg shadow-blue-500/30 hover:shadow-xl transition disabled:opacity-50 disabled:cursor-not-allowed"
                >
                    <CheckIcon class="w-5 h-5" />
                    {{ submitting ? 'กำลังบันทึก...' : 'บันทึกเครื่องมือ' }}
                </button>
            </div>
        </form>

        <EquipmentCodePickerModal
            :open="showCodePicker"
            :selected="selectedCode"
            @close="showCodePicker = false"
            @select="pickCode"
        />
    </div>
</template>
