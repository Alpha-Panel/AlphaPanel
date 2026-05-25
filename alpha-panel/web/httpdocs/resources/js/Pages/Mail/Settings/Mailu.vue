<template>
    <div class="space-y-5">
        <div
            v-if="!features.mailu"
            class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200"
        >
            <p class="font-medium">{{ t('Mailu is disabled') }}</p>
            <p class="mt-1">
                {{ t('Set MAIL_ENABLED=true in your root .env and start the Mailu external service. See README.') }}
            </p>
        </div>

        <div v-else class="space-y-5">
            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                <h4 class="mb-3 text-sm font-semibold text-gray-800 dark:text-white">
                    {{ t('Mailu endpoints') }}
                </h4>
                <dl class="grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
                    <div>
                        <dt class="text-gray-500">{{ t('Webmail') }}</dt>
                        <dd>
                            <a
                                v-if="mailDomain"
                                :href="`https://${mailDomain}:8443/`"
                                target="_blank"
                                rel="noopener"
                                class="text-brand-500 hover:underline"
                            >
                                https://{{ mailDomain }}:8443/
                            </a>
                            <span v-else class="text-gray-400">{{ t('Set MAIL_DOMAIN in .env') }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ t('Mailu admin UI') }}</dt>
                        <dd>
                            <a
                                v-if="mailDomain"
                                :href="`https://${mailDomain}:8443/admin`"
                                target="_blank"
                                rel="noopener"
                                class="text-brand-500 hover:underline"
                            >
                                https://{{ mailDomain }}:8443/admin
                            </a>
                            <span v-else class="text-gray-400">—</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ t('SMTP') }}</dt>
                        <dd class="font-mono text-xs">{{ mailDomain || 'mail.example.com' }}:587 (STARTTLS) / :465 (SMTPS)</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">{{ t('IMAP / POP3') }}</dt>
                        <dd class="font-mono text-xs">{{ mailDomain || 'mail.example.com' }}:993 / :995 (SSL)</dd>
                    </div>
                </dl>
            </div>

            <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900 dark:border-blue-700 dark:bg-blue-900/20 dark:text-blue-200">
                <p class="mb-2 font-semibold">{{ t('How Mailu works in this panel') }}</p>
                <ul class="list-disc space-y-1 pl-5">
                    <li>{{ t('Mailu runs as a Docker stack (mailu-front, mailu-admin, mailu-imap, mailu-smtp).') }}</li>
                    <li>{{ t('Panel manages mailboxes via Mailu admin REST API.') }}</li>
                    <li>{{ t('Run `php artisan mail:bootstrap` once after deploying Mailu to issue the API token.') }}</li>
                    <li>{{ t('Webmail (Snappymail) is the user-facing UI at the Webmail URL above.') }}</li>
                </ul>
            </div>

            <div v-if="!apiTokenSet" class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900 dark:border-red-700 dark:bg-red-900/20 dark:text-red-200">
                <p class="font-semibold">{{ t('Mailu API token not configured') }}</p>
                <p class="mt-1">
                    {{ t('Run on the server: docker compose exec alpha_panel_web php artisan mail:bootstrap') }}
                </p>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { useI18n } from '@/Composables/useI18n';

const { t } = useI18n();
const props = defineProps({
    features: { type: Object, required: true },
});

const page = usePage();
const mailDomain = computed(() => page.props.app?.mail_domain || page.props.mail?.mail_domain || null);
const apiTokenSet = computed(() => !!page.props.mail?.mailu_api_token_set);
</script>
