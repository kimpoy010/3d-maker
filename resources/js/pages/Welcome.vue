<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import ModelViewer from '@/components/ModelViewer.vue';
import { Button } from '@/components/ui/button';
import type { SharedData } from '@/types';

withDefaults(defineProps<{ canRegister?: boolean; sampleUrl?: string }>(), { canRegister: true, sampleUrl: '/samples/demo.glb' });

const page = usePage<SharedData>();
const user = computed(() => page.props.auth.user);

const steps = [
    { title: 'Upload', body: 'Add a clear photo of a person, pet, or favourite object.' },
    { title: 'Pick a style', body: 'Bobble head, fondant, pop-art, realistic or cartoon: choose the look you like.' },
    { title: 'Preview and approve', body: 'See your figure as a picture first. Happy with it? Approve it, or try again.' },
    { title: 'Get your 3D model', body: 'Spin it around in your browser and download it, ready for printing.' },
];
</script>

<template>
    <Head title="Your photo into a 3D figurine" />

    <div class="min-h-screen bg-background text-foreground">
        <header class="mx-auto flex max-w-6xl items-center justify-between px-4 py-4">
            <Link href="/" class="text-lg font-semibold tracking-tight">3D Maker</Link>
            <nav class="flex items-center gap-2">
                <template v-if="user">
                    <Button as-child><Link href="/create">Create</Link></Button>
                </template>
                <template v-else>
                    <Button variant="ghost" as-child><Link href="/login">Log in</Link></Button>
                    <Button v-if="canRegister" as-child><Link href="/register">Register</Link></Button>
                </template>
            </nav>
        </header>

        <main>
            <section class="mx-auto grid max-w-6xl items-center gap-10 px-4 py-12 md:grid-cols-2 md:py-20">
                <div class="space-y-6">
                    <h1 class="text-4xl font-semibold tracking-tight md:text-5xl">Your photo into a 3D figurine</h1>
                    <p class="max-w-md text-lg text-muted-foreground">
                        Turn a photo of yourself, your pet, or something you love into a 3D model you can view in your browser and download.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <Button size="lg" as-child><Link :href="user ? '/create' : '/register'">Start creating for free</Link></Button>
                    </div>
                    <p class="text-sm text-muted-foreground">New accounts start with a free ₱100 balance.</p>
                </div>
                <ModelViewer :src="sampleUrl" />
            </section>

            <section class="mx-auto max-w-6xl px-4 py-12">
                <h2 class="mb-8 text-2xl font-semibold tracking-tight">How it works</h2>
                <ol class="grid gap-6 md:grid-cols-2 lg:grid-cols-4">
                    <li v-for="(step, i) in steps" :key="step.title" class="rounded-xl border p-5">
                        <span class="text-sm text-muted-foreground">Step {{ i + 1 }}</span>
                        <h3 class="mt-1 font-medium">{{ step.title }}</h3>
                        <p class="mt-2 text-sm text-muted-foreground">{{ step.body }}</p>
                    </li>
                </ol>
            </section>

            <section class="mx-auto max-w-6xl px-4 py-16 text-center">
                <h2 class="text-2xl font-semibold tracking-tight">Ready to make your first model?</h2>
                <div class="mt-6">
                    <Button size="lg" as-child><Link :href="user ? '/create' : '/register'">Start creating for free</Link></Button>
                </div>
            </section>
        </main>
    </div>
</template>
