<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { usePolling } from '@/composables/usePolling';
import { peso } from '@/lib/money';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Stylization = {
    id: number;
    status: 'queued' | 'processing' | 'ready' | 'approved' | 'failed' | 'discarded';
    error: string | null;
    cost_credits: number;
    created_at: string;
    creation_id: number | null;
    style: { id: number; name: string; subject: string; look: string; credit_cost: number };
    urls: { original: string | null; result: string | null };
};

const props = defineProps<{ stylization: Stylization; balance: number; restyle_cost: number }>();

const approveForm = useForm({});
const retryForm = useForm({});

const working = computed(() => props.stylization.status === 'queued' || props.stylization.status === 'processing');
const ready = computed(() => props.stylization.status === 'ready');
const failed = computed(() => props.stylization.status === 'failed');
const buildCost = computed(() => props.stylization.style.credit_cost);
const canBuild = computed(() => props.balance >= buildCost.value);
const canRetry = computed(() => props.balance >= props.restyle_cost);

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Create', href: '/create' },
    { title: `${props.stylization.style.name} ${props.stylization.style.subject} preview`, href: `/stylizations/${props.stylization.id}` },
]);

const liveText = computed(() => {
    if (working.value) return 'Making your preview…';
    if (ready.value) return 'Your preview is ready';
    if (failed.value) return "We couldn't make a preview";
    return 'This preview is no longer available';
});

const polling = usePolling({ only: ['stylization', 'balance'], active: () => working.value });

// Inertia reuses this component when "Try again" redirects to a new preview: drop old errors.
watch(
    () => props.stylization.id,
    () => {
        approveForm.clearErrors();
        retryForm.clearErrors();
    },
);

function approve() {
    approveForm.post(`/stylizations/${props.stylization.id}/approve`, { preserveScroll: true });
}

function retry() {
    retryForm.post(`/stylizations/${props.stylization.id}/retry`);
}

function discard() {
    if (confirm('Throw this preview away?')) router.delete(`/stylizations/${props.stylization.id}`);
}
</script>

<template>
    <Head :title="`${stylization.style.name} ${stylization.style.subject} preview`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-4xl flex-col gap-6 p-4 md:p-6">
            <header>
                <h1 class="text-2xl font-semibold tracking-tight capitalize">{{ stylization.style.name }} {{ stylization.style.subject }} preview</h1>
                <p class="mt-1 text-sm text-muted-foreground">Check the look before we build the 3D model.</p>
            </header>

            <p class="sr-only" role="status" aria-live="polite">{{ liveText }}</p>

            <!-- making the preview -->
            <section v-if="working" class="grid gap-4 sm:grid-cols-2">
                <img v-if="stylization.urls.original" :src="stylization.urls.original" alt="Your original photo" class="aspect-[2/3] w-full rounded-xl border object-cover" />
                <div class="flex flex-col justify-center gap-3">
                    <p class="font-medium">Making your preview…</p>
                    <div class="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" aria-label="Preview progress">
                        <div class="h-full w-1/3 motion-safe:animate-pulse rounded-full bg-primary" />
                    </div>
                    <p class="text-sm text-muted-foreground">Usually under a minute. You can leave this page; the preview will wait for you under My Creations.</p>
                    <p v-if="polling.slow.value" class="text-sm text-amber-700 dark:text-amber-300" role="status">
                        This is taking longer than expected. If it doesn't finish soon you are refunded automatically.
                        <button type="button" class="underline" @click="polling.retry()">Check again</button>
                    </p>
                    <p v-if="polling.failed.value" class="text-sm text-destructive" role="alert">
                        We couldn't check on the preview just now.
                        <button type="button" class="underline" @click="polling.retry()">Check again</button>
                    </p>
                </div>
            </section>

            <!-- ready: compare and decide -->
            <section v-else-if="ready" class="space-y-5">
                <div class="grid gap-4 sm:grid-cols-2">
                    <figure class="space-y-2">
                        <img v-if="stylization.urls.original" :src="stylization.urls.original" alt="Your original photo" class="aspect-[2/3] w-full rounded-xl border object-cover" />
                        <figcaption class="text-center text-sm text-muted-foreground">Your photo</figcaption>
                    </figure>
                    <figure class="space-y-2">
                        <img v-if="stylization.urls.result" :src="stylization.urls.result" alt="Restyled preview of your figure" class="aspect-[2/3] w-full rounded-xl border object-cover" />
                        <figcaption class="text-center text-sm text-muted-foreground">Your figure preview</figcaption>
                    </figure>
                </div>

                <div class="flex flex-col gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:justify-between">
                    <p class="text-sm">
                        Balance: <strong>{{ peso(balance) }}</strong>.
                        <span class="text-muted-foreground">Building the 3D model costs {{ peso(buildCost) }}. It's refunded if the build fails.</span>
                    </p>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button v-if="canBuild" :disabled="approveForm.processing || retryForm.processing" @click="approve">
                            {{ approveForm.processing ? 'Starting…' : `Build 3D model (${peso(buildCost)})` }}
                        </Button>
                        <Button v-else as-child><Link href="/credits">Add balance to build</Link></Button>
                        <Button variant="outline" :disabled="!canRetry || retryForm.processing || approveForm.processing" @click="retry">
                            Try again ({{ peso(restyle_cost) }})
                        </Button>
                    </div>
                </div>

                <p v-if="!canRetry" class="text-sm text-muted-foreground">Not enough balance for another preview. <Link href="/credits" class="underline">Add balance</Link></p>
                <p v-if="approveForm.errors.approve" class="text-sm text-destructive" role="alert">{{ approveForm.errors.approve }}</p>
                <p v-if="retryForm.errors.retry" class="text-sm text-destructive" role="alert">{{ retryForm.errors.retry }}</p>

                <div class="flex gap-4 text-sm">
                    <Link href="/create" class="underline">Start over with another photo</Link>
                    <button type="button" class="text-muted-foreground underline" @click="discard">Throw this preview away</button>
                </div>
            </section>

            <!-- failed -->
            <section v-else-if="failed" class="space-y-4 rounded-xl border border-destructive/40 p-5" role="alert">
                <p class="font-medium text-destructive">We couldn't make a preview.</p>
                <p v-if="stylization.error" class="text-sm text-muted-foreground">{{ stylization.error }}</p>
                <p class="text-sm">Your {{ peso(stylization.cost_credits) }} has been refunded.</p>
                <div class="flex gap-3">
                    <Button as-child><Link href="/create">Try another photo</Link></Button>
                    <Button variant="outline" :disabled="!canRetry || retryForm.processing" @click="retry">Try again ({{ peso(restyle_cost) }})</Button>
                </div>
                <p v-if="!canRetry" class="text-sm text-muted-foreground">Not enough balance for another preview. <Link href="/credits" class="underline">Add balance</Link></p>
                <p v-if="retryForm.errors.retry" class="text-sm text-destructive">{{ retryForm.errors.retry }}</p>
            </section>

            <!-- discarded / anything else -->
            <section v-else class="rounded-xl border p-5 text-sm text-muted-foreground">
                This preview is no longer available. <Link href="/create" class="underline">Start a new one</Link>.
            </section>
        </div>
    </AppLayout>
</template>
