<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import { BellIcon, UserCircleIcon, ShieldCheckIcon, Bars3Icon, WrenchScrewdriverIcon } from '@heroicons/vue/24/outline';
import { useAuthStore }     from '../../stores/auth';
import { repairsApi }       from '../../api/repairs';
import { formatThaiFullDate, formatThaiTime } from '../../utils/thaiDate';

const emit = defineEmits(['toggle']);
const auth = useAuthStore();
const router = useRouter();
const now  = ref(new Date());

/* งานซ่อมที่ยังไม่จบ (ไม่ใช่ ปิดงาน/ยกเลิก) — แสดงเฉพาะ staff/admin */
const canManageRepairs = computed(() => auth.hasAnyRole(['admin', 'staff']));
const unfinishedCount = ref(0);
const badgeText = computed(() => (unfinishedCount.value > 99 ? '99+' : String(unfinishedCount.value)));

async function loadRepairCount() {
    if (!canManageRepairs.value) return;
    try {
        const { data } = await repairsApi.summary();
        unfinishedCount.value = data.unfinished ?? 0;
    } catch (_) { /* ignore */ }
}

function goToRepairs() {
    router.push({ name: 'repair.list', query: { manage: '1' } });
}

let timerId;
let countTimerId;
onMounted(() => {
    timerId = setInterval(() => { now.value = new Date(); }, 1000);
    loadRepairCount();
    countTimerId = setInterval(loadRepairCount, 60000); // refresh ทุก 60 วิ
});
onUnmounted(() => { clearInterval(timerId); clearInterval(countTimerId); });

const dateText  = computed(() => formatThaiFullDate(now.value));
const timeText  = computed(() => `เวลา ${formatThaiTime(now.value)}`);

const roleLabel = computed(() => {
    if (auth.isAdmin) return 'ADMIN';
    if (auth.isStaff) return 'STAFF';
    return 'USER';
});
</script>

<template>
    <header class="th-topbar h-[72px] border-b px-4 lg:px-6 flex items-center justify-between gap-4 shrink-0">

        <!-- Left: hamburger + date -->
        <div class="flex items-center gap-3">
            <!-- Sidebar toggle button -->
            <button
                @click="emit('toggle')"
                class="th-topbar-icon th-topbar-icon-btn p-2 rounded-xl transition-colors"
                title="เปิด/ปิด Sidebar"
            >
                <Bars3Icon class="w-5 h-5" />
            </button>

            <!-- Date + Time (hidden on very small screens) -->
            <div class="hidden sm:flex flex-col">
                <div class="th-topbar-text text-sm font-semibold">{{ dateText }}</div>
                <div class="th-topbar-sub  text-xs">{{ timeText }}</div>
            </div>
        </div>

        <!-- Right: repairs + bell + user -->
        <div class="flex items-center gap-3">
            <!-- งานซ่อมทั้งหมด (staff/admin) -->
            <button
                v-if="canManageRepairs"
                @click="goToRepairs"
                class="relative inline-flex items-center gap-2 pl-3 pr-3 py-2 rounded-xl bg-rose-50 text-rose-600 hover:bg-rose-100 transition-colors text-sm font-medium"
                title="งานซ่อมทั้งหมด (จัดการ/ลบได้)"
            >
                <WrenchScrewdriverIcon class="w-5 h-5" />
                <span class="hidden sm:inline">งานซ่อมทั้งหมด</span>
                <span
                    v-if="unfinishedCount > 0"
                    class="min-w-[20px] h-5 px-1.5 inline-flex items-center justify-center rounded-full bg-rose-600 text-white text-[11px] font-bold leading-none ring-2 ring-white animate-pulse"
                >
                    {{ badgeText }}
                </span>
            </button>

            <!-- Bell -->
            <button class="th-topbar-icon th-topbar-icon-btn relative p-2 rounded-xl transition-colors">
                <BellIcon class="w-5 h-5" />
                <span class="absolute top-1.5 right-1.5 w-2 h-2 bg-rose-500 rounded-full ring-2 ring-white"></span>
            </button>

            <!-- User info -->
            <div class="flex items-center gap-2.5 pl-3 border-l th-divider">
                <div class="w-9 h-9 rounded-full bg-gradient-to-br from-blue-100 to-indigo-100 flex items-center justify-center shrink-0">
                    <UserCircleIcon class="w-6 h-6 text-blue-600" />
                </div>
                <div class="hidden md:block leading-tight">
                    <div class="th-topbar-text text-sm font-semibold truncate max-w-[140px]">{{ auth.fullName }}</div>
                    <div class="flex items-center gap-1 text-[11px] text-emerald-600 mt-0.5">
                        <ShieldCheckIcon class="w-3 h-3" />
                        {{ roleLabel }}
                    </div>
                </div>
            </div>
        </div>
    </header>
</template>
