<template>
    <div class="relative" ref="dropdownRef">
        <button class="flex items-center text-gray-700 dark:text-gray-400" @click.prevent="toggleDropdown">
            <span class="flex h-11 w-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-brand-100 text-brand-600 dark:bg-brand-500/20 dark:text-brand-400 font-medium lg:mr-3">
                <img
                    v-if="user?.avatar_url"
                    :src="user.avatar_url"
                    :alt="user.name"
                    class="h-full w-full object-cover"
                />
                <span v-else>{{ userInitials }}</span>
            </span>
            <span class="hidden lg:block">
              <span class="block mr-1 font-medium text-theme-sm">{{ user?.name }}</span>
              <span class="block text-start text-theme-xs text-gray-500 dark:text-gray-400">{{ isAdmin }}</span>
            </span>
            <ChevronDownIcon class="hidden lg:block" :class="{ 'rotate-180': dropdownOpen }" />
        </button>

        <div
            v-if="dropdownOpen"
            class="absolute right-0 mt-4.25 flex w-65 flex-col rounded-2xl border border-gray-200 bg-white p-3 shadow-theme-lg dark:border-gray-800 dark:bg-gray-dark"
        >
            <div>
                <span class="block font-medium text-gray-700 text-theme-sm dark:text-gray-400">
                    {{ user?.name }}
                </span>
                <span class="mt-0.5 block text-theme-xs text-gray-500 dark:text-gray-400">
                    {{ user?.email }}
                </span>
            </div>

            <ul class="flex flex-col gap-1 pt-4 pb-3 border-b border-gray-200 dark:border-gray-800">
                <li>
                    <Link
                        :href="route('user.security')"
                        class="flex items-center gap-3 px-3 py-2 font-medium text-gray-700 rounded-lg group text-theme-sm hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-300"
                        @click="closeDropdown"
                    >
                        <SettingsIcon class="text-gray-500 group-hover:text-gray-700 dark:group-hover:text-gray-300" />
                        {{ t('Security Settings') }}
                    </Link>
                </li>
                <li>
                    <Link
                        :href="route('user.notification-settings.index')"
                        class="flex items-center gap-3 px-3 py-2 font-medium text-gray-700 rounded-lg group text-theme-sm hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-300"
                        @click="closeDropdown"
                    >
                        <i class="bx bx-bell w-6 h-6 text-2xl text-gray-500 group-hover:text-gray-700 dark:group-hover:text-gray-300"></i>
                        {{ t('Notification Settings') }}
                    </Link>
                </li>
                <li>
                    <button
                        @click="lockScreen"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left font-medium text-gray-700 group text-theme-sm hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-300"
                    >
                      <i class="bx bx-lock-alt w-6 h-6 text-2xl text-gray-500 group-hover:text-gray-700 dark:group-hover:text-gray-300"></i>
                        {{ t('Lock Screen') }}
                    </button>
                </li>
                <li>
                    <button
                        type="button"
                        :aria-expanded="cbExpanded"
                        aria-haspopup="menu"
                        @click="cbExpanded = !cbExpanded"
                        class="flex w-full items-center gap-3 rounded-lg px-3 py-2 text-left font-medium text-gray-700 group text-theme-sm hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-300"
                    >
                        <i class="bx bx-low-vision w-6 h-6 text-2xl text-gray-500 group-hover:text-gray-700 dark:group-hover:text-gray-300"></i>
                        <span class="flex-1">
                            {{ t('Color Blind Mode') }}
                            <span
                                v-if="cbActiveLabel && !cbExpanded"
                                class="block text-theme-xs text-gray-500 dark:text-gray-500"
                            >
                                {{ cbActiveLabel }}
                            </span>
                        </span>
                        <ChevronDownIcon
                            class="transition-transform"
                            :class="{ 'rotate-180': cbExpanded }"
                        />
                    </button>
                    <ul
                        v-if="cbExpanded"
                        role="menu"
                        class="mt-1 flex flex-col gap-0.5 pl-9"
                    >
                        <li v-for="opt in cbOptions" :key="opt.value">
                            <button
                                type="button"
                                role="menuitemradio"
                                :aria-checked="cbMode === opt.value"
                                @click="setCbMode(opt.value)"
                                class="flex w-full items-center justify-between rounded-lg px-3 py-1.5 text-left text-theme-sm hover:bg-gray-100 dark:hover:bg-white/5"
                                :class="
                                    cbMode === opt.value
                                        ? 'font-medium text-brand-500 dark:text-brand-400'
                                        : 'text-gray-600 dark:text-gray-400'
                                "
                            >
                                <span>{{ t(opt.label) }}</span>
                                <i v-if="cbMode === opt.value" class="bx bx-check text-lg"></i>
                            </button>
                        </li>
                    </ul>
                </li>
            </ul>

            <button
                @click="signOut"
                class="flex items-center gap-3 px-3 py-2 mt-3 font-medium text-gray-700 rounded-lg group text-theme-sm hover:bg-gray-100 hover:text-gray-700 dark:text-gray-400 dark:hover:bg-white/5 dark:hover:text-gray-300"
            >
                <LogoutIcon class="text-gray-500 group-hover:text-gray-700 dark:group-hover:text-gray-300" />
                {{ t('Sign out') }}
            </button>
        </div>
    </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted, watch } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { ChevronDownIcon, LogoutIcon, SettingsIcon } from '@/Components/Icons';
import { useI18n } from '@/Composables/useI18n';
import { useColorBlindMode, type ColorBlindMode } from '@/Composables/useColorBlindMode';
import type { SharedProps } from '@/types/inertia';

const page = usePage<SharedProps>();
const user = computed(() => page.props.auth?.user);
const { t } = useI18n();
const isAdmin = computed(() => (user.value?.is_admin ? t('Admin') : t('User')));

const userInitials = computed(() => {
    const name = user.value?.name ?? '';
    return name
        .split(' ')
        .map((n) => n[0])
        .join('')
        .toUpperCase()
        .slice(0, 2);
});

const dropdownOpen = ref(false);
const dropdownRef = ref<HTMLElement | null>(null);

const { mode: cbMode, setMode: setCbMode } = useColorBlindMode();
const cbExpanded = ref(false);

const cbOptions: { value: ColorBlindMode; label: string }[] = [
    { value: 'off', label: 'Off' },
    { value: 'protanopia', label: 'Protanopia' },
    { value: 'deuteranopia', label: 'Deuteranopia' },
    { value: 'tritanopia', label: 'Tritanopia' },
];

const cbActiveLabel = computed(() => {
    if (cbMode.value === 'off') return '';
    const opt = cbOptions.find((o) => o.value === cbMode.value);
    return opt ? t(opt.label) : '';
});

watch(dropdownOpen, (open) => {
    if (!open) cbExpanded.value = false;
});

const toggleDropdown = () => {
    dropdownOpen.value = !dropdownOpen.value;
};

const closeDropdown = () => {
    dropdownOpen.value = false;
};

const signOut = () => {
    router.post(route('logout'));
    closeDropdown();
};

const lockScreen = () => {
    router.post(route('lockscreen'));
    closeDropdown();
};

const handleClickOutside = (event: MouseEvent) => {
    if (dropdownRef.value && !dropdownRef.value.contains(event.target as Node)) {
        closeDropdown();
    }
};

onMounted(() => {
    document.addEventListener('click', handleClickOutside);
});

onUnmounted(() => {
    document.removeEventListener('click', handleClickOutside);
});
</script>
