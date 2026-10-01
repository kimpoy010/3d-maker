<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted } from 'vue';
import ModelViewer from '@/components/ModelViewer.vue';
import { Button } from '@/components/ui/button';
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
    urls: { source: string; model: string | null; thumbnail: string | null; download: string | null };
};

const props = defineProps<{ creation: Creation }>();

const POLL_MS = 3000;
let timer: ReturnType<typeof setInterval> | null = null;

const working = computed(() => props.creation.status === 'queued' || props.creation.status === 'processing');
const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'My Creations', href: '/creations' },
    { title: `${props.creation.style.name} ${props.creation.style.subject}`, href: `/creations/${props.creation.id}` },
]);

function startPolling() {
    if (timer) return;
    timer = setInterval(() => {
        if (!working.value) return stopPolling();
        router.reload({ only: ['creation'] });
    }, POLL_MS);
}

function stopPolling() {
    if (timer) clearInterval(timer);
    timer = null;
}

function remove() {
    if (confirm('Delete this creation? This cannot be undone.')) router.delete(`/creations/${props.creation.id}`);
}

onMounted(() => working.value && startPolling());
onBeforeUnmount(stopPolling);
</script>

<template>
    <Head :title="`${creation.style.name} ${creation.style.subject}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-3xl flex-col gap-6 p-4 md:p-6">
            <header>
                <h1 class="text-2xl font-semibold tracking-tight capitalize">{{ creation.style.name }} {{ creation.style.subject }}</h1>
                <p class="mt-1 text-sm text-muted-foreground capitalize">{{ creation.status }}</p>
            </header>

            <!-- queued / processing -->
            <section v-if="working" class="grid gap-4 sm:grid-cols-2" aria-live="polite">
                <img :src="creation.urls.source" alt="Your photo" class="aspect-square w-full rounded-xl border object-cover" />
                <div class="flex flex-col justify-center gap-3">
                    <p class="font-medium">Building your 3D model…</p>
                    <div class="h-2 overflow-hidden rounded-full bg-muted" role="progressbar" :aria-valuenow="creation.progress ?? 0" aria-valuemin="0" aria-valuemax="100">
                        <div class="h-full bg-primary transition-all" :style="{ width: `${creation.progress ?? 5}%` }" />
                    </div>
                    <p class="text-sm text-muted-foreground">This can take a few minutes. You can leave this page — it will keep going and appear in My Creations.</p>
                </div>
            </section>

            <!-- succeeded -->
            <section v-else-if="creation.status === 'succeeded' && creation.urls.model" class="space-y-4">
                <ModelViewer :src="creation.urls.model" />
                <div class="flex flex-wrap gap-3">
                    <Button v-if="creation.urls.download" as-child><a :href="creation.urls.download">Download GLB</a></Button>
                    <Button variant="outline" @click="remove">Delete</Button>
                </div>
            </section>

            <!-- failed -->
            <section v-else class="space-y-4 rounded-xl border border-destructive/40 p-5">
                <p class="font-medium text-destructive">We couldn't build this model.</p>
                <p v-if="creation.error" class="text-sm text-muted-foreground">{{ creation.error }}</p>
                <p class="text-sm">Your {{ creation.cost_credits }} credits have been refunded.</p>
                <div class="flex gap-3">
                    <Button as-child><Link href="/create">Try again</Link></Button>
                    <Button variant="outline" @click="remove">Delete</Button>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
