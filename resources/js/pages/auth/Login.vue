<script setup>
import { ref } from 'vue';
import { useRouter, useRoute } from 'vue-router';
import { useAuthStore } from '../../stores/auth';
import { useSettingsStore } from '../../stores/settings';
import { providerIdApi } from '../../api/users';
import { EyeIcon, EyeSlashIcon, ExclamationCircleIcon } from '@heroicons/vue/24/outline';
import Swal from 'sweetalert2';

const router   = useRouter();
const route    = useRoute();
const auth     = useAuthStore();
const appSettings = useSettingsStore();

const username     = ref('');
const password     = ref('');
const showPassword = ref(false);
const showHint     = ref(false);

async function submit() {
    if (!username.value.trim() || !password.value) return;
    const ok = await auth.login(username.value.trim(), password.value);
    if (ok) {
        router.push(route.query.redirect || { name: 'dashboard' });
    } else {
        Swal.fire({
            icon: 'error',
            title: 'เข้าสู่ระบบไม่สำเร็จ',
            text: auth.error || 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง',
            confirmButtonColor: '#2563eb',
        });
    }
}

async function startProviderId() {
    try {
        const { data } = await providerIdApi.start();
        if (!data.configured) {
            Swal.fire({
                icon: 'info',
                title: 'Provider ID ยังไม่พร้อมใช้งาน',
                text: data.message || 'ระบบยังไม่ได้ตั้งค่า MOPH Provider ID',
                confirmButtonColor: '#2563eb',
            });
            return;
        }
        window.location.href = data.authorize_url;
    } catch (e) {
        Swal.fire({ icon: 'error', title: 'ไม่สามารถเริ่ม Provider ID', text: e.response?.data?.message || '' });
    }
}
</script>

<template>
    <!-- ── Full-screen layout ──────────────────────────────────── -->
    <div class="min-h-screen relative overflow-hidden flex">

        <!-- Background image -->
        <img
            src="/img/bg.jpg"
            alt=""
            class="absolute inset-0 w-full h-full object-cover object-center pointer-events-none select-none"
        />
        <!-- Overlay -->
        <div class="absolute inset-0 bg-gradient-to-r from-slate-900/80 via-slate-800/60 to-slate-900/70"></div>

        <!-- ── Left panel: branding (hidden on mobile) ── -->
        <div class="relative z-10 hidden lg:flex flex-1 flex-col items-start justify-center px-16">
            <div class="w-20 h-20 rounded-3xl bg-white/10 backdrop-blur-md border border-white/20 flex items-center justify-center mb-6 shadow-xl overflow-hidden">
                <img
                    v-if="appSettings.logoUrl"
                    :src="appSettings.logoUrl"
                    :key="appSettings.logoUrl"
                    alt="logo"
                    class="w-full h-full object-contain p-1"
                />
                <span v-else class="text-white font-bold text-3xl">CK</span>
            </div>
            <h1 class="text-4xl font-extrabold text-white leading-tight">{{ appSettings.systemName }}</h1>
            <p class="text-blue-200 text-lg mt-2">ระบบบริหารจัดการเครื่องมือทางการแพทย์</p>
            <p class="text-slate-400 text-sm mt-1">โรงพยาบาลเชียงกลาง จ.น่าน</p>

            <div class="mt-10 grid grid-cols-2 gap-4 max-w-sm">
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/10">
                    <div class="text-white font-bold text-2xl">100%</div>
                    <div class="text-slate-300 text-xs mt-1">ครอบคลุมทุกเครื่องมือ</div>
                </div>
                <div class="bg-white/10 backdrop-blur-sm rounded-2xl p-4 border border-white/10">
                    <div class="text-white font-bold text-2xl">Real-time</div>
                    <div class="text-slate-300 text-xs mt-1">ติดตามสถานะซ่อม</div>
                </div>
            </div>
        </div>

        <!-- ── Right panel: login form ── -->
        <div class="relative z-10 w-full lg:w-[460px] flex flex-col justify-center px-8 sm:px-12 py-10 bg-slate-900/90 backdrop-blur-xl border-l border-white/10 shadow-2xl">

            <!-- Mobile brand -->
            <div class="flex items-center gap-3 mb-8 lg:hidden">
                <div class="w-10 h-10 rounded-xl bg-blue-600 overflow-hidden flex items-center justify-center">
                    <img v-if="appSettings.logoUrl" :src="appSettings.logoUrl" :key="appSettings.logoUrl" alt="logo" class="w-full h-full object-contain" />
                    <span v-else class="text-white font-bold text-lg">CK</span>
                </div>
                <div>
                    <div class="text-white font-bold text-lg">{{ appSettings.systemName }}</div>
                    <div class="text-slate-400 text-xs">โรงพยาบาลเชียงกลาง</div>
                </div>
            </div>

            <!-- Heading -->
            <div class="mb-8">
                <h2 class="text-2xl font-bold text-white">เข้าสู่ระบบ</h2>
                <p class="text-slate-400 text-sm mt-1">กรอกข้อมูลเพื่อเข้าใช้งาน {{ appSettings.systemName }}</p>
            </div>

            <!-- Form -->
            <form @submit.prevent="submit" class="space-y-5">

                <!-- Username -->
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">
                        ชื่อผู้ใช้ <span class="text-rose-400">*</span>
                    </label>
                    <input
                        v-model="username"
                        type="text"
                        required
                        autocomplete="username"
                        placeholder="กรอกชื่อผู้ใช้งาน"
                        class="w-full px-4 py-3 rounded-xl bg-white/10 border border-white/20 text-white placeholder-slate-500 focus:border-blue-400 focus:ring-2 focus:ring-blue-400/30 outline-none transition"
                    />
                </div>

                <!-- Password -->
                <div>
                    <label class="block text-sm font-medium text-slate-300 mb-1.5">
                        รหัสผ่าน <span class="text-rose-400">*</span>
                    </label>
                    <div class="relative">
                        <input
                            v-model="password"
                            :type="showPassword ? 'text' : 'password'"
                            required
                            autocomplete="current-password"
                            placeholder="••••••••"
                            class="w-full px-4 py-3 rounded-xl bg-white/10 border border-white/20 text-white placeholder-slate-500 focus:border-blue-400 focus:ring-2 focus:ring-blue-400/30 outline-none transition pr-12"
                        />
                        <button
                            type="button"
                            @click="showPassword = !showPassword"
                            class="absolute inset-y-0 right-3 flex items-center text-slate-400 hover:text-white transition"
                        >
                            <EyeSlashIcon v-if="showPassword" class="w-5 h-5" />
                            <EyeIcon v-else class="w-5 h-5" />
                        </button>
                    </div>
                </div>

                <!-- Submit -->
                <button
                    type="submit"
                    :disabled="auth.loading"
                    class="w-full py-3 rounded-xl bg-blue-600 hover:bg-blue-500 text-white font-semibold shadow-lg shadow-blue-600/30 transition disabled:opacity-60 disabled:cursor-not-allowed flex items-center justify-center gap-2"
                >
                    <svg v-if="auth.loading" class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    {{ auth.loading ? 'กำลังเข้าสู่ระบบ...' : 'เข้าสู่ระบบ' }}
                </button>

                <!-- Divider -->
                <div class="relative">
                    <div class="absolute inset-0 flex items-center">
                        <div class="w-full border-t border-white/10"></div>
                    </div>
                    <div class="relative flex justify-center">
                        <span class="px-3 text-xs text-slate-500 bg-transparent">หรือ</span>
                    </div>
                </div>

                <!-- Provider ID -->
                <button
                    type="button"
                    @click="startProviderId"
                    class="w-full py-2.5 px-4 rounded-xl border border-white/20 bg-white/5 hover:bg-white/10 transition flex items-center justify-center gap-3 group"
                >
                    <img
                        src="/img/provider.png"
                        alt="MOPH Provider ID"
                        class="h-7 object-contain"
                    />
                    <span class="text-sm font-medium text-slate-300 group-hover:text-white transition">
                        เข้าใช้งานผ่าน MOPH Provider ID
                    </span>
                </button>

            </form>

            <!-- Forgot / Reset link -->
            <div class="mt-6 flex items-center justify-between text-xs">
                <button
                    @click="showHint = !showHint"
                    class="text-slate-500 hover:text-slate-300 transition flex items-center gap-1"
                >
                    <ExclamationCircleIcon class="w-3.5 h-3.5" />
                    ลืมรหัสผ่าน?
                </button>
                <router-link
                    :to="{ name: 'reset-password' }"
                    class="text-blue-400 hover:text-blue-300 transition font-medium"
                >
                    รีเซ็ตรหัสผ่านฉุกเฉิน →
                </router-link>
            </div>

            <!-- Credentials hint (toggle) -->
            <div
                v-if="showHint"
                class="mt-4 bg-amber-500/10 border border-amber-400/30 rounded-xl p-4 text-xs"
            >
                <p class="text-amber-300 font-semibold mb-2">ข้อมูลเริ่มต้นระบบ (default)</p>
                <div class="space-y-1 text-slate-300">
                    <div class="flex gap-2"><span class="text-slate-500 w-20">Username:</span><code class="text-amber-200">admin</code></div>
                    <div class="flex gap-2"><span class="text-slate-500 w-20">Password:</span><code class="text-amber-200">adminck</code></div>
                </div>
                <p class="text-slate-500 mt-2">หากเปลี่ยนรหัสผ่านแล้ว ใช้ <router-link :to="{ name: 'reset-password' }" class="text-blue-400 underline">หน้ารีเซ็ตฉุกเฉิน</router-link></p>
            </div>

            <p class="text-center text-xs text-slate-600 mt-8">
                {{ appSettings.systemName }} v0.1 · โรงพยาบาลเชียงกลาง
            </p>
        </div>
    </div>
</template>
