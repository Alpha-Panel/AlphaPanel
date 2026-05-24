<template>
    <div class="space-y-4 text-sm text-gray-600 dark:text-gray-300">
        <p v-if="features.mailu">
            {{ t('Mailu is currently enabled. Manage relay settings via the Outbound Relay tab. Mailu container settings are managed in your root .env (MAIL_DOMAIN, MAIL_DATA_PATH, etc.) and require a docker compose restart to take effect.') }}
        </p>
        <p v-else>
            {{ t('Mailu is disabled. Set MAIL_ENABLED=true in your root .env and enable the mailu external-service to provision a local mail server.') }}
        </p>
    </div>
</template>

<script setup>
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();
defineProps({
    features: { type: Object, required: true },
});
</script>
