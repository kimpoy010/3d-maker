<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem } from '@/types';

type Creation = {
    id: number;
    status: 'queued' | 'processing' | 'succeeded' | 'failed';
    created_at: string;
    style: { name: string; subject: string; look: string };
    urls: { source: string; thumbnail: string | null };
};

defineProps<{ creations: Creation[] }>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'My Creations', href: '/creations' }];
const badge: Record<Creation['status'], string> = {
    queued: 'bg-muted text-muted-foreground',
    processing: 'bg-amber-100 text-amber-900 dark:bg-amber-900/30 dark:text-amber-200',
    succeeded: 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/30 dark:text-emerald-200',
    failed: 'bg-red-100 text-red-900 dark:bg-red-900/30 dark:text-red-200',
};
const date = (iso: string) => new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });
</script>

<template>
    <Head title="My Creations" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex w-full max-w-5xl flex-col gap-6 p-4 md:p-6">
            <header class="flex items-center justify-between">
                <h1 class="text-2xl font-semibold tracking-tight">My Creations</h1>
                <Button as-child><Link href="/create">New creation</Link></Button>
            </header>

            <div v-if="creations.length === 0" class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground">
                You haven't made anything yet.
                <Link href="/create" class="underline">Create your first 3D model</Link>.
            </div>

            <div v-else class="grid grid-cols-2 gap-4 md:grid-cols-3 lg:grid-cols-4">
                <Link
                    v-for="c in creations"
                    :key="c.id"
                    :href="`/creations/${c.id}`"
                    class="group overflow-hidden rounded-xl border transition-shadow hover:shadow-md"
                >
                    <div class="aspect-square bg-muted/40">
                        <img :src="c.urls.thumbnail ?? c.urls.source" :alt="c.style.name" class="size-full object-cover" loading="lazy" />
                    </div>
                    <div class="flex items-center justify-between gap-2 p-3 text-sm">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ c.style.name }} {{ c.style.subject }}</div>
                            <div class="text-xs text-muted-foreground">{{ date(c.created_at) }}</div>
                        </div>
                        <span class="shrink-0 rounded-full px-2 py-0.5 text-xs capitalize" :class="badge[c.status]">{{ c.status }}</span>
                    </div>
                </Link>
            </div>
        </div>
    </AppLayout>
</template>
