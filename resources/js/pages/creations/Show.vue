<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ModelViewer from '@/components/ModelViewer.vue';
import { Button } from '@/components/ui/button';
import { usePolling } from '@/composables/usePolling';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Creation = {
    id: number;
    status: 'queued' | 'processing' | 'succeeded' | 'failed';
    error: string | null;
    progress: number | null;
    cost_credits: number;
    created_at: string;
    style: { name: string; subject: string; look: string };
    urls: { source: string; model: string | null; thumbnail: string | null; download: string | null; download_stl: string | null };
};

const props = defineProps<{ creation: Creation }>();

const retryForm = useForm({});
const deleting = ref(false);

const working = computed(() => props.creation.status === 'queued' || props.creation.status === 'processing');
const failed = computed(() => props.creation.status === 'failed');
const busy = computed(() => retryForm.processing || deleting.value);
const credits = (n: number) => `${n} credit${n === 1 ? '' : 's'}`;
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'My Creations', href: '/creations' },
    { title: `${props.creation.style.name} ${props.creation.style.subject}`, href: `/creations/${props.creation.id}` },
]);

const liveText = computed(() => {
    if (working.value) return 'Building your 3D model…';
    if (props.creation.status === 'succeeded' && props.creation.urls.model) return 'Your 3D model is ready';
    if (failed.value) return "We couldn't build this model";
    return 'The model file is not available';
});

const polling = usePolling({ only: ['creation'], active: () => working.value });

function retry() {
    retryForm.post(`/creations/${props.creation.id}/retry`);
}

function remove() {
    if (!confirm('Delete this creation? This cannot be undone.')) return;
    router.delete(`/creations/${props.creation.id}`, {
        onStart: () => {
            deleting.value = true;
        },
        onFinish: () => {
            deleting.value = false;
        },
    });
}
</script>

<template>
    <Head :title="`${creation.style.name} ${creation.style.subject}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <header>
                <h1 class="text-2xl font-semibold tracking-tight capitalize">{{ creation.style.name }} {{ creation.style.subject }}</h1>
                <p class="mt-1 text-sm text-muted-foreground capitalize">{{ creation.status }}</p>
            </header>

            <p class="sr-only" role="status" aria-live="polite">{{ liveText }}</p>

            <!-- queued / processing -->
            <section v-if="working" class="grid gap-4 sm:grid-cols-2">
                <img :src="creation.urls.source" alt="The approved preview being turned into 3D" class="aspect-[2/3] w-full rounded-xl border object-cover" />
                <div class="flex flex-col justify-center gap-3">
                    <p class="font-medium">Building your 3D model…</p>
                    <div
                        class="h-2 overflow-hidden rounded-full bg-muted"
                        role="progressbar"
                        aria-label="3D model progress"
                        :aria-valuenow="creation.progress ?? undefined"
                        aria-valuemin="0"
                        aria-valuemax="100"
                    >
                        <div
                            class="h-full rounded-full bg-primary transition-all"
                            :class="{ 'motion-safe:animate-pulse': creation.progress === null }"
                            :style="{ width: `${creation.progress ?? 5}%` }"
                        />
                    </div>
                    <p class="text-sm text-muted-foreground">This can take a few minutes. You can leave this page; it will keep going and appear in My Creations.</p>
                    <p v-if="polling.slow.value" class="text-sm text-amber-700 dark:text-amber-300" role="status">
                        This is taking longer than expected. If it doesn't finish soon your credits are refunded automatically.
                        <button type="button" class="underline" @click="polling.retry()">Check again</button>
                    </p>
                    <p v-if="polling.failed.value" class="text-sm text-destructive" role="alert">
                        We couldn't check on your model just now.
                        <button type="button" class="underline" @click="polling.retry()">Check again</button>
                    </p>
                </div>
            </section>

            <!-- succeeded -->
            <section v-else-if="creation.status === 'succeeded' && creation.urls.model" class="space-y-4">
                <ModelViewer :src="creation.urls.model" />
                <div class="flex flex-wrap gap-3">
                    <Button v-if="creation.urls.download" as-child><a :href="creation.urls.download">Download GLB</a></Button>
                    <Button v-if="creation.urls.download_stl" variant="outline" as-child><a :href="creation.urls.download_stl">Download STL</a></Button>
                    <Button variant="outline" :disabled="busy" @click="remove">Delete</Button>
                </div>
            </section>

            <!-- failed: the only state that mentions a refund -->
            <section v-else-if="failed" class="space-y-4 rounded-xl border border-destructive/40 p-5">
                <p class="font-medium text-destructive">We couldn't build this model.</p>
                <p v-if="creation.error" class="text-sm text-muted-foreground">{{ creation.error }}</p>
                <p class="text-sm">Your {{ credits(creation.cost_credits) }} {{ creation.cost_credits === 1 ? 'has' : 'have' }} been refunded. You can try the same image again, or start over.</p>
                <p v-if="retryForm.errors.retry" class="text-sm text-destructive" role="alert">{{ retryForm.errors.retry }}</p>
                <div class="flex flex-wrap gap-3">
                    <Button :disabled="busy" @click="retry">Try again ({{ credits(creation.cost_credits) }})</Button>
                    <Button variant="outline" as-child><Link href="/create">Start over</Link></Button>
                    <Button variant="outline" :disabled="busy" @click="remove">Delete</Button>
                </div>
            </section>

            <!-- anything else, e.g. succeeded without a model file -->
            <section v-else class="space-y-4 rounded-xl border p-5">
                <p class="font-medium">The model file isn't available.</p>
                <p class="text-sm text-muted-foreground">Please contact support or delete this creation and try again.</p>
                <Button variant="outline" :disabled="busy" @click="remove">Delete</Button>
            </section>
        </div>
    </AppLayout>
</template>
