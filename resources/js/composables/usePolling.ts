import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

type PollingOptions = {
    /** Props to reload on every tick, e.g. ['stylization']. */
    only: string[];
    /** Polling continues only while this returns true. */
    active: () => boolean;
    intervalMs?: number;
    /** Stop (and set `slow`) after this long. */
    maxMs?: number;
};

/**
 * Re-fetches some Inertia props every few seconds while `active()` is true. Unlike a bare
 * setInterval it stops on request errors (404, 419, 5xx, offline) instead of looping on
 * Inertia's error modal, and stops after `maxMs` so a stuck job does not poll forever.
 */
export function usePolling(options: PollingOptions) {
    const { only, active, intervalMs = 3000, maxMs = 12 * 60 * 1000 } = options;
    const failed = ref(false);
    const slow = ref(false);

    let timer: ReturnType<typeof setInterval> | null = null;
    let startedAt = 0;
    let removers: Array<() => void> = [];

    function stop() {
        if (timer) clearInterval(timer);
        timer = null;
    }

    function start() {
        if (timer) return;
        startedAt = Date.now();
        timer = setInterval(() => {
            if (!active()) return stop();
            if (Date.now() - startedAt > maxMs) {
                slow.value = true;
                return stop();
            }
            router.reload({ only });
        }, intervalMs);
    }

    /** Clear the failure flags and try again. */
    function retry() {
        failed.value = false;
        slow.value = false;
        if (active()) start();
    }

    onMounted(() => {
        // Inertia raises these for non-Inertia responses (404/419/5xx) and network errors.
        // Cancelling them keeps its generic error modal from popping up every 3 seconds.
        removers = [
            router.on('invalid', (event) => {
                event.preventDefault();
                failed.value = true;
                stop();
            }),
            router.on('exception', (event) => {
                event.preventDefault();
                failed.value = true;
                stop();
            }),
        ];
        if (active()) start();
    });

    // Inertia reuses this component when navigating between two records of the same page (e.g.
    // "Try again" redirects to a new preview), so onMounted does not run again: follow active().
    watch(active, (isActive) => {
        if (!isActive) return stop();
        failed.value = false;
        slow.value = false;
        start();
    });

    onBeforeUnmount(() => {
        stop();
        removers.forEach((remove) => remove());
    });

    return { failed, slow, retry, start };
}
