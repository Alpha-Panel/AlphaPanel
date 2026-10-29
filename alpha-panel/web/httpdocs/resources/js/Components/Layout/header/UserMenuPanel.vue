<template>
    <div class="ap-pop" role="menu" @click.stop>
        <div class="mb-1 border-b border-line-2 px-2.5 py-2">
            <div class="truncate text-sm font-semibold">{{ user?.name }}</div>
            <div class="truncate text-[11px] text-fg-3">{{ user?.email }}</div>
        </div>
        <Link :href="route('user.security')" class="ap-pop-item hover:no-underline" @click="emit('close')">
            <i class="bx bx-lock text-[15px]"></i>{{ t('Security Settings') }}
        </Link>
        <Link :href="route('user.notification-settings.index')" class="ap-pop-item hover:no-underline" @click="emit('close')">
            <i class="bx bx-bell text-[15px]"></i>{{ t('Notification Settings') }}
        </Link>
        <button type="button" class="ap-pop-item" @click="lock">
            <i class="bx bx-lock-alt text-[15px]"></i>{{ t('Lock Screen') }}
        </button>
        <div class="my-1 h-px bg-line-2"></div>
        <button type="button" class="ap-pop-item hover:!text-err" @click="signOut">
            <i class="bx bx-log-out text-[15px]"></i>{{ t('Sign out') }}
        </button>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { useI18n } from '@/Composables/useI18n';
import type { SharedProps } from '@/types/inertia';

const emit = defineEmits<{ close: [] }>();
const page = usePage<SharedProps>();
const user = computed(() => page.props.auth?.user);
const { t } = useI18n();

function signOut(): void {
    emit('close');
    router.post(route('logout'));
}
function lock(): void {
    emit('close');
    router.post(route('lockscreen'));
}
</script>
