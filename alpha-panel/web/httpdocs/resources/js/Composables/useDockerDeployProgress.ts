import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import type { SharedProps } from '@/types/inertia';

export interface DockerDeployJob {
    serviceId: number;
    serviceName: string;
    percent: number;
    message: string;
    status: 'running' | 'success' | 'error';
}

// Module-level singleton state (same pattern as useProvisionProgress.ts)
const jobs = ref(new Map<number, DockerDeployJob>());
let channelSubscribed = false;
let channelName: string | null = null;

function triggerReactivity() {
    jobs.value = new Map(jobs.value);
}

export function useDockerDeployProgress() {
    const activeJobs = computed(() => Array.from(jobs.value.values()));

    function handleProgress(event: { service_id: number; service_name: string; percent: number; message: string }) {
        jobs.value.set(event.service_id, {
            serviceId: event.service_id,
            serviceName: event.service_name,
            percent: event.percent,
            message: event.message,
            status: 'running',
        });
        triggerReactivity();
    }

    function handleComplete(event: { service_id: number; service_name: string }) {
        const job = jobs.value.get(event.service_id);
        if (job) {
            job.status = 'success';
            job.percent = 100;
            job.message = 'Deployed successfully';
            triggerReactivity();
        }
        setTimeout(() => {
            jobs.value.delete(event.service_id);
            triggerReactivity();
        }, 3000);
    }

    function handleFailed(event: { service_id: number; service_name: string; error: string }) {
        const job = jobs.value.get(event.service_id);
        if (job) {
            job.status = 'error';
            job.message = event.error;
            triggerReactivity();
        }
        setTimeout(() => {
            jobs.value.delete(event.service_id);
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
            .listen('.DockerDeployProgress', handleProgress)
            .listen('.DockerDeployCompleted', handleComplete)
            .listen('.DockerDeployFailed', handleFailed);

        channelSubscribed = true;
    }

    function unsubscribe() {
        if (!channelSubscribed || !channelName || typeof window.Echo === 'undefined') return;
        window.Echo.private(channelName)
            .stopListening('.DockerDeployProgress')
            .stopListening('.DockerDeployCompleted')
            .stopListening('.DockerDeployFailed');
        channelSubscribed = false;
    }

    function dismissJob(serviceId: number) {
        jobs.value.delete(serviceId);
        triggerReactivity();
    }

    return {
        jobs: activeJobs,
        subscribe,
        unsubscribe,
        dismissJob,
    };
}
