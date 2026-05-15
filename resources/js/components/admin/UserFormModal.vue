<script setup>
import { computed, ref, watch } from 'vue';
import Swal from 'sweetalert2';
import BaseModal from '../common/BaseModal.vue';
import { usersApi } from '../../api/users';
import { useMasterStore } from '../../stores/master';

const props = defineProps({
    open: { type: Boolean, default: false },
    editing: { type: Object, default: null },
});
const emit = defineEmits(['close', 'saved']);

const master = useMasterStore();

const form = ref({
    username: '',
    full_name: '',
    employee_code: '',
    email: '',
    phone: '',
    password: '',
    department_id: null,
    role: 'staff',
    is_active: true,
    moph_client_key: '',
    moph_secret_key: '',
});

const showMophSection = ref(false);
const errors = ref({});
const submitting = ref(false);

const isEdit = computed(() => !!props.editing);

watch(() => props.open, async (open) => {
    if (open) {
        await master.loadAll();
        if (props.editing) {
            form.value = {
                username: props.editing.username || '',
                full_name: props.editing.full_name || props.editing.name || '',
                employee_code: props.editing.employee_code || '',
                email: props.editing.email,
                phone: props.editing.phone || '',
                password: '',
                department_id: props.editing.department?.id || null,
                role: (props.editing.roles?.[0]?.name || props.editing.roles?.[0] || 'staff'),
                is_active: !!props.editing.is_active,
                moph_client_key: props.editing.moph_client_key || '',
                moph_secret_key: '',  // never pre-fill secret
            };
            showMophSection.value = !!props.editing.moph_client_key || !!props.editing.moph_has_secret_key;
        } else {
            form.value = {
                username: '', full_name: '', employee_code: '', email: '', phone: '',
                password: '', department_id: null, role: 'staff', is_active: true,
                moph_client_key: '', moph_secret_key: '',
            };
            showMophSection.value = false;
        }
        errors.value = {};
    }
});

async function submit() {
    submitting.value = true;
    errors.value = {};
    try {
        const payload = { ...form.value };
        if (isEdit.value && !payload.password) delete payload.password;
        if (isEdit.value && !payload.moph_secret_key) delete payload.moph_secret_key;
        // Remove empty moph_client_key if not set (leave as null in DB)
        if (!payload.moph_client_key) payload.moph_client_key = null;
        if (!payload.moph_secret_key && !isEdit.value) payload.moph_secret_key = null;
        if (isEdit.value) {
            await usersApi.update(props.editing.id, payload);
        } else {
            await usersApi.store(payload);
        }
        Swal.fire({ icon: 'success', title: 'บันทึกแล้ว', timer: 1200, showConfirmButton: false });
        emit('saved');
    } catch (e) {
        if (e.response?.status === 422) {
            errors.value = e.response.data.errors || {};
        } else {
            Swal.fire({ icon: 'error', title: 'ล้มเหลว', text: e.response?.data?.message || '' });
        }
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <BaseModal
        :open="open"
        @close="emit('close')"
        :title="isEdit ? 'แก้ไขผู้ใช้' : 'เพิ่มผู้ใช้ใหม่'"
        size="2xl"
    >
        <form @submit.prevent="submit" class="space-y-4">

            <!-- Username — login identifier, full-width & highlighted -->
            <div class="bg-blue-50 border border-blue-200 rounded-xl p-3">
                <label class="text-xs font-semibold text-blue-700 block mb-1">
                    ชื่อผู้ใช้ (Username) <span class="text-rose-500">*</span>
                    <span class="font-normal text-blue-500 ml-1">— ใช้เข้าสู่ระบบ</span>
                </label>
                <input
                    v-model="form.username"
                    required
                    autocomplete="username"
                    placeholder="เช่น john_doe, nurse01, doctor_smith"
                    class="w-full px-3 py-2 rounded-xl border border-blue-300 text-sm font-mono focus:border-blue-500 focus:ring-2 focus:ring-blue-200 bg-white"
                />
                <p class="text-[11px] text-blue-500 mt-1">ใช้ตัวอักษร a-z, A-Z, 0-9, _ และ - เท่านั้น · ห้ามซ้ำกับผู้ใช้อื่น</p>
                <p v-if="errors.username" class="text-xs text-rose-500 mt-0.5">{{ errors.username[0] }}</p>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="text-xs font-medium text-slate-700 block mb-1">ชื่อ-นามสกุล <span class="text-rose-500">*</span></label>
                    <input v-model="form.full_name" required class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:border-blue-500" />
                    <p v-if="errors.full_name" class="text-xs text-rose-500 mt-0.5">{{ errors.full_name[0] }}</p>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700 block mb-1">รหัสพนักงาน</label>
                    <input v-model="form.employee_code" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:border-blue-500" />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700 block mb-1">Email <span class="text-rose-500">*</span></label>
                    <input v-model="form.email" type="email" required class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:border-blue-500" />
                    <p v-if="errors.email" class="text-xs text-rose-500 mt-0.5">{{ errors.email[0] }}</p>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700 block mb-1">เบอร์โทร</label>
                    <input v-model="form.phone" type="tel" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:border-blue-500" />
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700 block mb-1">หน่วยงาน</label>
                    <select v-model.number="form.department_id" class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm bg-white focus:border-blue-500">
                        <option :value="null">— ไม่ระบุ —</option>
                        <option v-for="d in master.departments" :key="d.id" :value="d.id">{{ d.code }} — {{ d.name_th }}</option>
                    </select>
                </div>
                <div>
                    <label class="text-xs font-medium text-slate-700 block mb-1">สิทธิ์ <span class="text-rose-500">*</span></label>
                    <select v-model="form.role" required class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm bg-white focus:border-blue-500">
                        <option value="admin">Admin (สิทธิ์เต็ม)</option>
                        <option value="staff">Staff (เครื่องมือ + ซ่อม + สอบเทียบ)</option>
                        <option value="user">User (แจ้งซ่อม + Dashboard)</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="text-xs font-medium text-slate-700 block mb-1">
                    รหัสผ่าน
                    <span v-if="!isEdit" class="text-rose-500">*</span>
                    <span v-else class="text-[10px] text-slate-400 ml-2">(เว้นว่างถ้าไม่ต้องการเปลี่ยน)</span>
                </label>
                <input v-model="form.password" type="password" :required="!isEdit" minlength="6"
                    class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm focus:border-blue-500" />
                <p v-if="errors.password" class="text-xs text-rose-500 mt-0.5">{{ errors.password[0] }}</p>
            </div>

            <label class="flex items-center gap-2 px-3 py-2 rounded-xl bg-slate-50 cursor-pointer">
                <input v-model="form.is_active" type="checkbox" class="w-4 h-4 accent-blue-600" />
                <span class="text-sm">เปิดใช้งานบัญชี</span>
            </label>

            <!-- MOPH Alert Personal Keys -->
            <div class="border border-slate-200 rounded-xl overflow-hidden">
                <button type="button"
                    @click="showMophSection = !showMophSection"
                    class="w-full flex items-center justify-between px-4 py-3 bg-slate-50 hover:bg-slate-100 text-sm font-medium text-slate-700 transition-colors">
                    <span class="flex items-center gap-2">
                        <span class="text-base">🔔</span>
                        MOPH Alert (แจ้งเตือนสถานะซ่อม LINE ส่วนตัว)
                        <span v-if="isEdit && editing?.moph_has_secret_key && !form.moph_client_key && !form.moph_secret_key"
                            class="text-[10px] px-2 py-0.5 rounded-full bg-green-100 text-green-700 font-semibold">
                            ตั้งค่าแล้ว
                        </span>
                    </span>
                    <svg :class="showMophSection ? 'rotate-180' : ''" class="w-4 h-4 transition-transform text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div v-show="showMophSection" class="p-4 space-y-3">
                    <p class="text-xs text-slate-500 leading-relaxed">
                        ผูก MOPH Alert Token ส่วนตัว เพื่อรับแจ้งเตือนสถานะการซ่อม
                        (<strong>รับเรื่อง / กำลังดำเนินการ / ซ่อมเสร็จ</strong>) ผ่าน LINE โดยตรง
                    </p>
                    <div>
                        <label class="text-xs font-medium text-slate-700 block mb-1">Client Key</label>
                        <input v-model="form.moph_client_key"
                            placeholder="กรอก client-key จาก MOPH Alert"
                            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm font-mono focus:border-blue-500" />
                        <p v-if="errors.moph_client_key" class="text-xs text-rose-500 mt-0.5">{{ errors.moph_client_key[0] }}</p>
                    </div>
                    <div>
                        <label class="text-xs font-medium text-slate-700 block mb-1">
                            Secret Key
                            <span v-if="isEdit && editing?.moph_has_secret_key" class="text-[10px] text-slate-400 ml-1">(เว้นว่างถ้าไม่ต้องการเปลี่ยน)</span>
                        </label>
                        <input v-model="form.moph_secret_key"
                            type="password"
                            :placeholder="isEdit && editing?.moph_has_secret_key ? '••••••••••••••••' : 'กรอก secret-key จาก MOPH Alert'"
                            class="w-full px-3 py-2 rounded-xl border border-slate-200 text-sm font-mono focus:border-blue-500" />
                        <p v-if="errors.moph_secret_key" class="text-xs text-rose-500 mt-0.5">{{ errors.moph_secret_key[0] }}</p>
                    </div>
                    <p class="text-[11px] text-amber-600 bg-amber-50 rounded-lg px-3 py-2">
                        💡 กู้ Token ได้ที่แอป MOPH Alert → ตั้งค่า → Token การแจ้งเตือน
                    </p>
                </div>
            </div>
        </form>

        <template #footer>
            <button @click="emit('close')" class="px-4 py-2 rounded-lg text-sm text-slate-600 hover:bg-slate-100">ยกเลิก</button>
            <button @click="submit" :disabled="submitting"
                class="px-5 py-2 rounded-lg bg-blue-600 text-white text-sm font-semibold disabled:opacity-50">
                {{ submitting ? 'กำลังบันทึก...' : (isEdit ? 'บันทึกการแก้ไข' : 'เพิ่มผู้ใช้') }}
            </button>
        </template>
    </BaseModal>
</template>
