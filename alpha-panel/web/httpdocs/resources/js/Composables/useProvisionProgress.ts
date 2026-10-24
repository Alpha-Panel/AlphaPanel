import { ref, computed } from 'vue';
import { usePage } from '@inertiajs/vue3';
import axios from 'axios';
import type { SharedProps } from '@/types/inertia';
import { useI18n } from '@/Composables/useI18n';

export interface ProvisionJob {
    domainId: number;
    fqdn: string;
    percent: number;
    message: string;
    status: 'running' | 'success' | 'error';
    kind?: 'provision' | 'delete';
}

interface DeletionStatus {
    state: 'running' | 'failed' | 'deleted' | 'idle';
    percent: number;
    message: string | null;
}

// Module-level singleton state (same pattern as useToast.ts)
const jobs = ref(new Map<number, ProvisionJob>());
let channelSubscribed = false;
let channelName: string | null = null;
let deletionPollTimer: ReturnType<typeof setTimeout> | null = null;
let translate: ReturnType<typeof useI18n>['t'] = (key) => key;

function triggerReactivity() {
    jobs.value = new Map(jobs.value);
}

function finishJob(domainId: number, status: 'success' | 'error', message: string, dismissAfterMs: number) {
    const job = jobs.value.get(domainId);
    if (!job) return;

    job.status = status;
    job.percent = 100;
    job.message = message;
    triggerReactivity();

    setTimeout(() => {
        if (jobs.value.get(domainId) === job) {
            jobs.value.delete(domainId);
            triggerReactivity();
        }
    }, dismissAfterMs);
}

function runningDeletionIds(): number[] {
    return Array.from(jobs.value.values())
        .filter((job) => job.kind === 'delete' && job.status === 'running')
        .map((job) => job.domainId);
}

// Deletion progress is polled, not broadcast, so the bar moves even when Reverb is down.
function scheduleDeletionPoll() {
    if (deletionPollTimer === null && runningDeletionIds().length > 0) {
        deletionPollTimer = setTimeout(() => void pollDeletions(), 2000);
    }
}

async function pollDeletions() {
    deletionPollTimer = null;
    const ids = runningDeletionIds();
    if (ids.length === 0) return;

    try {
        // Hardcoded path: a stale Ziggy route list on the server would make route() throw.
        const response = await axios.get('/domains-deletion-status', { params: { ids } });
        const statuses: Record<string, DeletionStatus> = response.data?.data ?? {};

        ids.forEach((id) => {
            const job = jobs.value.get(id);
            const status = statuses[id];
            if (!job || !status) return;

            if (status.state === 'deleted') {
                finishJob(id, 'success', translate('Domain :fqdn has been deleted successfully.', { fqdn: job.fqdn }), 4000);
            } else if (status.state === 'failed' || status.state === 'idle') {
                finishJob(id, 'error', status.message ?? translate('Delete failed.'), 10000);
            } else {
                job.percent = status.percent;
                job.message = status.message ?? job.message;
            }
        });
        triggerReactivity();
    } catch {
        // Transient (network, deploy restart): keep the jobs and try again on the next tick.
    }

    scheduleDeletionPoll();
}

export function useProvisionProgress() {
    translate = useI18n().t;

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

    /**
     * Show a progress toast for a queued domain deletion and poll it until it ends.
     * Safe to call repeatedly: a deletion that is already tracked is left alone.
     */
    function trackDeletion(domainId: number, fqdn: string) {
        const existing = jobs.value.get(domainId);
        if (existing?.kind === 'delete' && existing.status === 'running') return;

        jobs.value.set(domainId, {
            domainId,
            fqdn,
            percent: 0,
            message: translate('Waiting in queue...'),
            status: 'running',
            kind: 'delete',
        });
        triggerReactivity();
        scheduleDeletionPoll();
    }

    return {
        jobs: activeJobs,
        subscribe,
        unsubscribe,
        dismissJob,
        trackDeletion,
    };
}
