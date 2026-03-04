<template>
    <Head :title="t('Sign Up')" />
    <ThemeProvider>
        <FullScreenLayout>
            <div class="relative flex items-center justify-center min-h-screen p-6 bg-white z-1 dark:bg-gray-900 sm:p-0">
                <div class="flex flex-col flex-1 w-full max-w-md mx-auto">
                    <div class="mb-5 sm:mb-8">
                        <h1 class="mb-2 text-2xl font-semibold text-gray-800 dark:text-white/90 sm:text-title-sm">
                            {{ t('Sign Up') }}
                        </h1>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            {{ t('Create your account to get started') }}
                        </p>
                    </div>

                    <form @submit.prevent="submit">
                        <div class="space-y-5">
                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Name') }}
                                </label>
                                <input
                                    v-model="form.name"
                                    type="text"
                                    :placeholder="t('Enter your name')"
                                    class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800"
                                />
                                <p v-if="form.errors.name" class="mt-1 text-sm text-error-500">{{ form.errors.name }}</p>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Email') }}
                                </label>
                                <input
                                    v-model="form.email"
                                    type="email"
                                    :placeholder="t('Enter your email')"
                                    class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800"
                                />
                                <p v-if="form.errors.email" class="mt-1 text-sm text-error-500">{{ form.errors.email }}</p>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Password') }}
                                </label>
                                <input
                                    v-model="form.password"
                                    type="password"
                                    :placeholder="t('Create a password')"
                                    class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800"
                                />
                                <p v-if="form.errors.password" class="mt-1 text-sm text-error-500">{{ form.errors.password }}</p>
                            </div>

                            <div>
                                <label class="mb-1.5 block text-sm font-medium text-gray-700 dark:text-gray-400">
                                    {{ t('Confirm Password') }}
                                </label>
                                <input
                                    v-model="form.password_confirmation"
                                    type="password"
                                    :placeholder="t('Confirm your password')"
                                    class="dark:bg-dark-900 h-11 w-full rounded-lg border border-gray-300 bg-transparent px-4 py-2.5 text-sm text-gray-800 shadow-theme-xs placeholder:text-gray-400 focus:border-brand-300 focus:outline-hidden focus:ring-3 focus:ring-brand-500/10 dark:border-gray-700 dark:bg-gray-900 dark:text-white/90 dark:placeholder:text-white/30 dark:focus:border-brand-800"
                                />
                            </div>

                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="flex items-center justify-center w-full px-4 py-3 text-sm font-medium text-white transition rounded-lg bg-brand-500 shadow-theme-xs hover:bg-brand-600 disabled:opacity-50"
                            >
                                <span v-if="form.processing">{{ t('Creating account...') }}</span>
                                <span v-else>{{ t('Sign Up') }}</span>
                            </button>
                        </div>
                    </form>

                    <p class="mt-5 text-sm font-normal text-center text-gray-700 dark:text-gray-400 sm:text-start">
                        {{ t('Already have an account?') }}
                        <Link
                            :href="route('login')"
                            class="text-brand-500 hover:text-brand-600 dark:text-brand-400"
                        >
                            {{ t('Sign In') }}
                        </Link>
                    </p>
                </div>
            </div>
        </FullScreenLayout>
    </ThemeProvider>
</template>

<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import ThemeProvider from '@/Components/Layout/ThemeProvider.vue';
import FullScreenLayout from '@/Components/Layout/FullScreenLayout.vue';
import { useI18n } from '@/Composables/useI18n';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});
const { t } = useI18n();

const submit = () => {
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>
