import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/types/inertia';

export interface ProvisionJob {
    domainId: number;
    fqdn: string;
    percent: number;
    message: string;
    status: 'running' | 'success' | 'error';
}

// Module-level singleton state (same pattern as useToast.ts)
const jobs = ref(new Map<number, ProvisionJob>());
let channelSubscribed = false;
let channelName: string | null = null;

function triggerReactivity() {
    jobs.value = new Map(jobs.value);
}

export function useProvisionProgress() {
    const activeJobs = computed(() => Array.from(jobs.value.values()));

    function handleProgress(event: { domain_id: number; fqdn: string; percent: number; message: string }) {
        jobs.value.set(event.domain_id, {
            domainId: event.domain_id,
            fqdn: event.fqdn,
            percent: event.percent,
            message: event.message,
            status: 'running',
        });
        triggerReactivity();
    }

    function handleComplete(event: { domain_id: number; fqdn: string }) {
        const job = jobs.value.get(event.domain_id);
        if (job) {
            job.status = 'success';
            job.percent = 100;
            job.message = 'Provisioned successfully';
            triggerReactivity();
        }
        setTimeout(() => {
            jobs.value.delete(event.domain_id);
            triggerReactivity();
        }, 3000);
    }

    function handleFailed(event: { domain_id: number; fqdn: string; error: string }) {
        const job = jobs.value.get(event.domain_id);
        if (job) {
            job.status = 'error';
            job.message = event.error;
            triggerReactivity();
        }
        setTimeout(() => {
            jobs.value.delete(event.domain_id);
            triggerReactivity();
        }, 5000);
    }

    function subscribe() {
        if (channelSubscribed || typeof window.Echo === 'undefined') return;

        const page = usePage<SharedProps>();
        const userId = page.props.auth?.user?.id;
        if (!userId) return;

        channelName = `user.${userId}`;
        window.Echo.private(channelName)
            .listen('.DomainProvisionProgress', handleProgress)
            .listen('.DomainProvisioned', handleComplete)
            .listen('.DomainProvisionFailed', handleFailed);

        channelSubscribed = true;
    }

    function unsubscribe() {
        if (!channelSubscribed || !channelName || typeof window.Echo === 'undefined') return;
        window.Echo.private(channelName)
            .stopListening('.DomainProvisionProgress')
            .stopListening('.DomainProvisioned')
            .stopListening('.DomainProvisionFailed');
        channelSubscribed = false;
    }

    function dismissJob(domainId: number) {
        jobs.value.delete(domainId);
        triggerReactivity();
    }

    return {
        jobs: activeJobs,
        subscribe,
        unsubscribe,
        dismissJob,
    };
}
