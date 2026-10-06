<script setup lang="ts">
import ModelViewer from '@/components/ModelViewer.vue';
import { Button } from '@/components/ui/button';
import { usePolling } from '@/composables/usePolling';
import AppLayout from '@/layouts/AppLayout.vue';
import { loadArtwork, loadArtworkFromUrl } from '@/lib/artwork';
import { analyze, bodyProxy, extrude, paletteFor, type Analysis, type BodyConfig, type ClickerSettings, type Part, type Pixels } from '@/lib/clicker';
import { peso } from '@/lib/money';
import type { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, shallowRef, watch } from 'vue';

type Body = { id: number; name: string; description: string | null; preview: string | null; config: BodyConfig };
type ClickerData = {
    id: number;
    name: string;
    status: 'draft' | 'building' | 'ready' | 'failed';
    error: string | null;
    settings: ClickerSettings;
    body_id: number;
    unlocked: boolean;
    editable: boolean;
    created_at: string;
    urls: { artwork: string | null; thumbnail: string | null; threemf: string | null; stl_zip: string | null };
};

const props = defineProps<{ clicker: ClickerData | null; bodies: Body[]; balance: number; unlock_cost: number; max_colors: number }>();

const emptySettings = (): ClickerSettings => ({ colors: [], scale: 1, offset_x: 0, offset_y: 0, outline_mm: 0, outline_hex: '#111111' });
const clone = <T,>(value: T): T => JSON.parse(JSON.stringify(value));

// ---- editable state
const name = ref(props.clicker?.name ?? 'My clicker');
const bodyId = ref<number>(props.clicker?.body_id ?? props.bodies[0]?.id ?? 0);
const settings = ref<ClickerSettings>(props.clicker ? clone(props.clicker.settings) : emptySettings());
const colorCount = ref(settings.value.colors.length || 4);
const pixels = shallowRef<Pixels | null>(null);
const artworkBlob = shallowRef<Blob | null>(null); // only set when the user picked a new file
const analysis = shallowRef<Analysis | null>(null);
const parts = shallowRef<Part[]>([]);
const notes = ref<string[]>([]);
const loadError = ref('');
const viewer = ref<InstanceType<typeof ModelViewer> | null>(null);

const body = computed(() => props.bodies.find((b) => b.id === bodyId.value) ?? props.bodies[0]);
const editable = computed(() => props.clicker === null || props.clicker.editable);
const previewParts = computed<Part[]>(() => (body.value ? [bodyProxy(body.value.config.cap), ...parts.value] : []));

// ---- the preview pipeline: a slow half (colours, tracing) and a fast half (heights)
const analysisKey = computed(() =>
    JSON.stringify([
        bodyId.value,
        settings.value.scale,
        settings.value.offset_x,
        settings.value.offset_y,
        settings.value.outline_mm,
        settings.value.colors.map((c) => [c.hex, c.enabled]),
    ]),
);
const heightKey = computed(() => JSON.stringify([settings.value.colors.map((c) => c.height_mm), settings.value.outline_hex]));
let timer: ReturnType<typeof setTimeout> | null = null;

function rebuild() {
    if (!analysis.value || !body.value) {
        parts.value = [];
        return;
    }
    parts.value = extrude(analysis.value, settings.value, body.value.config);
}

function runAnalysis() {
    timer = null;
    if (!pixels.value || !body.value || settings.value.colors.length === 0) {
        analysis.value = null;
        notes.value = [];
        parts.value = [];
        return;
    }
    try {
        analysis.value = analyze(pixels.value, settings.value, body.value.config);
        notes.value = analysis.value.notes;
        rebuild();
    } catch (error) {
        loadError.value = error instanceof Error ? error.message : 'The preview could not be built.';
    }
}

function schedule() {
    if (timer) clearTimeout(timer);
    timer = setTimeout(runAnalysis, 200);
}

watch([pixels, analysisKey], schedule);
watch(heightKey, rebuild);
onBeforeUnmount(() => {
    if (timer) clearTimeout(timer);
});

// ---- artwork and colours
const defaultHeight = (index: number) => Math.min(body.value?.config.max_layer_height ?? 3, Math.round((0.6 + 0.4 * index) * 10) / 10);

function resetPalette() {
    if (!pixels.value) return;
    settings.value.colors = paletteFor(pixels.value, colorCount.value).map((hex, index) => ({ hex, height_mm: defaultHeight(index), enabled: true }));
}

async function useFile(file: File | undefined) {
    if (!file || !editable.value) return;
    loadError.value = '';
    try {
        const loaded = await loadArtwork(file);
        pixels.value = loaded.pixels;
        artworkBlob.value = loaded.png;
        resetPalette();
    } catch (error) {
        loadError.value = error instanceof Error ? error.message : 'That file could not be used.';
    }
}

const onPick = (event: Event) => useFile((event.target as HTMLInputElement).files?.[0]);
const onDrop = (event: DragEvent) => useFile(event.dataTransfer?.files?.[0]);

function setColorCount(count: number) {
    colorCount.value = count;
    resetPalette();
}

function removeColor(index: number) {
    settings.value.colors.splice(index, 1);
    colorCount.value = settings.value.colors.length;
}

// Inertia may reuse this page for another design (after the first save, or after Duplicate): follow the id.
let loadedFor: number | null = props.clicker?.id ?? null;

async function loadSaved() {
    const url = props.clicker?.urls.artwork;
    if (!url) return;
    try {
        pixels.value = (await loadArtworkFromUrl(url)).pixels;
    } catch {
        loadError.value = 'The saved artwork could not be loaded.';
    }
}

onMounted(loadSaved);

// ---- saving
const snapshotKey = () => JSON.stringify([name.value, bodyId.value, settings.value]);
const savedKey = ref(snapshotKey());
const dirty = computed(() => artworkBlob.value !== null || snapshotKey() !== savedKey.value);
const saveForm = useForm<Record<string, unknown>>({});
const errors = computed(() => Object.values(saveForm.errors).filter(Boolean) as string[]);
const canSave = computed(
    () =>
        editable.value &&
        !saveForm.processing &&
        !!body.value &&
        (props.clicker === null ? pixels.value !== null : dirty.value) &&
        settings.value.colors.length > 0,
);

// After a save the server sends back the stored design; take it as the new baseline.
watch(
    () => props.clicker,
    (clicker) => {
        if (!clicker) return;
        name.value = clicker.name;
        bodyId.value = clicker.body_id;
        settings.value = clone(clicker.settings);
        colorCount.value = settings.value.colors.length;
        artworkBlob.value = null;
        savedKey.value = snapshotKey();

        if (clicker.id !== loadedFor) {
            loadedFor = clicker.id;
            void loadSaved();
        }
    },
);

async function save() {
    const thumbnail = await viewer.value?.snapshot();
    const data = {
        ...(props.clicker ? { _method: 'put' } : {}),
        name: name.value,
        clicker_body_id: bodyId.value,
        settings: {
            ...settings.value,
            colors: settings.value.colors.map((c) => ({ hex: c.hex, height_mm: c.height_mm, enabled: c.enabled ? 1 : 0 })),
        },
        ...(artworkBlob.value ? { artwork: new File([artworkBlob.value], 'artwork.png', { type: 'image/png' }) } : {}),
        ...(thumbnail ? { thumbnail: new File([thumbnail], 'thumbnail.png', { type: 'image/png' }) } : {}),
    };

    saveForm.transform(() => data).post(props.clicker ? `/clickers/${props.clicker.id}` : '/clickers', { forceFormData: true, preserveScroll: true });
}

// ---- paying, duplicating, deleting, downloading
const unlockForm = useForm<Record<string, unknown>>({});
const canUnlock = computed(() => !!props.clicker && !props.clicker.unlocked && ['draft', 'failed'].includes(props.clicker.status));
const affordable = computed(() => props.balance >= props.unlock_cost);
const busy = computed(() => saveForm.processing || unlockForm.processing);

function unlock() {
    if (props.clicker) unlockForm.post(`/clickers/${props.clicker.id}/unlock`, { preserveScroll: true });
}

function duplicate() {
    if (props.clicker) router.post(`/clickers/${props.clicker.id}/duplicate`);
}

function remove() {
    if (props.clicker && window.confirm('Delete this design and its files?')) router.delete(`/clickers/${props.clicker.id}`);
}

const polling = usePolling({ only: ['clicker', 'balance'], active: () => props.clicker?.status === 'building' });

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
    { title: 'Clicker maker', href: '/clickers' },
    { title: props.clicker ? props.clicker.name : 'New clicker', href: props.clicker ? `/clickers/${props.clicker.id}` : '/clickers/create' },
]);
</script>

<template>
    <Head :title="clicker ? clicker.name : 'New clicker'" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div v-if="bodies.length === 0" class="mx-auto w-full max-w-xl p-6 text-center text-sm text-muted-foreground">
            No clicker bodies are available yet. Please check back soon.
        </div>

        <div v-else class="mx-auto grid w-full max-w-6xl gap-6 p-4 md:p-6 lg:grid-cols-[minmax(0,26rem)_minmax(0,1fr)]">
            <!-- controls -->
            <fieldset :disabled="!editable" class="min-w-0 space-y-6 border-0 p-0">
                <p v-if="clicker && !editable" class="rounded-xl border bg-muted/40 p-3 text-sm">
                    This design is locked because it has been unlocked for download. Use <strong>Duplicate to edit</strong> to change it.
                </p>

                <section class="space-y-2">
                    <label for="clicker-name" class="text-sm font-medium">Name</label>
                    <input
                        id="clicker-name"
                        v-model="name"
                        type="text"
                        maxlength="60"
                        class="w-full rounded-lg border bg-background px-3 py-2 text-sm"
                    />
                </section>

                <section class="space-y-2">
                    <h2 class="text-sm font-medium">1. Clicker body</h2>
                    <div class="grid gap-2">
                        <label
                            v-for="b in bodies"
                            :key="b.id"
                            class="flex cursor-pointer items-start gap-3 rounded-xl border p-3 text-sm"
                            :class="bodyId === b.id ? 'border-primary ring-2 ring-primary/30' : 'hover:bg-muted/40'"
                        >
                            <input v-model="bodyId" type="radio" :value="b.id" class="mt-1" />
                            <span>
                                <span class="block font-medium">{{ b.name }}</span>
                                <span v-if="b.description" class="block text-xs text-muted-foreground">{{ b.description }}</span>
                                <span class="block text-xs text-muted-foreground"
                                    >Cap {{ b.config.cap.width }} × {{ b.config.cap.height }} mm<template v-if="b.config.switch">
                                        · {{ b.config.switch }}</template
                                    ></span
                                >
                            </span>
                        </label>
                    </div>
                </section>

                <section class="space-y-2">
                    <h2 class="text-sm font-medium">2. Artwork</h2>
                    <label
                        class="flex cursor-pointer flex-col items-center gap-1 rounded-xl border border-dashed p-6 text-center text-sm text-muted-foreground hover:bg-muted/40"
                        @dragover.prevent
                        @drop.prevent="onDrop"
                    >
                        <span class="font-medium text-foreground">{{ pixels ? 'Replace artwork' : 'Drop artwork here or click to browse' }}</span>
                        <span>PNG, JPG or SVG. Transparent backgrounds work best.</span>
                        <input type="file" accept="image/png,image/jpeg,image/svg+xml" class="sr-only" @change="onPick" />
                    </label>
                    <p v-if="loadError" class="text-sm text-destructive" role="alert">{{ loadError }}</p>
                </section>

                <template v-if="pixels">
                    <section class="space-y-3">
                        <h2 class="text-sm font-medium">3. Colours</h2>
                        <div class="flex flex-wrap items-center gap-2 text-sm">
                            <span class="text-muted-foreground">Number of colours</span>
                            <button
                                v-for="n in max_colors"
                                :key="n"
                                type="button"
                                class="size-8 rounded-md border text-xs"
                                :class="colorCount === n ? 'border-primary bg-primary text-primary-foreground' : 'hover:bg-muted'"
                                @click="setColorCount(n)"
                            >
                                {{ n }}
                            </button>
                        </div>
                        <p class="text-xs text-muted-foreground">Changing the number picks the colours again from your artwork.</p>

                        <ul class="space-y-2">
                            <li v-for="(c, i) in settings.colors" :key="i" class="flex flex-wrap items-center gap-3 rounded-xl border p-2 text-sm">
                                <input
                                    v-model="c.hex"
                                    type="color"
                                    class="size-9 shrink-0 cursor-pointer rounded border"
                                    :aria-label="`Colour ${i + 1}`"
                                />
                                <label class="flex items-center gap-1">
                                    <span class="text-xs text-muted-foreground">Height</span>
                                    <input
                                        v-model.number="c.height_mm"
                                        type="number"
                                        min="0.1"
                                        :max="body.config.max_layer_height"
                                        step="0.1"
                                        class="w-16 rounded-md border bg-background px-2 py-1"
                                    />
                                    <span class="text-xs text-muted-foreground">mm</span>
                                </label>
                                <label class="flex items-center gap-1 text-xs">
                                    <input v-model="c.enabled" type="checkbox" />
                                    Print
                                </label>
                                <button v-if="settings.colors.length > 1" type="button" class="ml-auto text-xs underline" @click="removeColor(i)">
                                    Remove
                                </button>
                            </li>
                        </ul>
                    </section>

                    <section class="space-y-3">
                        <h2 class="text-sm font-medium">4. Size, position and outline</h2>
                        <label class="block text-sm">
                            <span class="flex justify-between"
                                ><span>Artwork size</span><span class="text-muted-foreground">{{ Math.round(settings.scale * 100) }}%</span></span
                            >
                            <input v-model.number="settings.scale" type="range" min="0.1" max="1" step="0.01" class="w-full" />
                        </label>
                        <label class="block text-sm">
                            <span class="flex justify-between"
                                ><span>Move left / right</span><span class="text-muted-foreground">{{ settings.offset_x }} mm</span></span
                            >
                            <input v-model.number="settings.offset_x" type="range" min="-20" max="20" step="0.5" class="w-full" />
                        </label>
                        <label class="block text-sm">
                            <span class="flex justify-between"
                                ><span>Move up / down</span><span class="text-muted-foreground">{{ settings.offset_y }} mm</span></span
                            >
                            <input v-model.number="settings.offset_y" type="range" min="-20" max="20" step="0.5" class="w-full" />
                        </label>
                        <label class="block text-sm">
                            <span class="flex justify-between"
                                ><span>Outline between colours</span><span class="text-muted-foreground">{{ settings.outline_mm }} mm</span></span
                            >
                            <input v-model.number="settings.outline_mm" type="range" min="0" max="2" step="0.1" class="w-full" />
                        </label>
                        <label v-if="settings.outline_mm > 0" class="flex items-center gap-2 text-sm">
                            <input
                                v-model="settings.outline_hex"
                                type="color"
                                class="size-9 cursor-pointer rounded border"
                                aria-label="Outline colour"
                            />
                            Outline colour
                        </label>
                    </section>
                </template>
            </fieldset>

            <!-- preview and actions -->
            <div class="min-w-0 space-y-4 lg:sticky lg:top-4 lg:self-start">
                <ModelViewer ref="viewer" :parts="previewParts" default-look="textured" :auto-rotate="false" />

                <section
                    v-if="notes.length"
                    class="rounded-xl border border-amber-300 bg-amber-50 p-3 text-sm dark:border-amber-700 dark:bg-amber-950/30"
                    aria-label="Print notes"
                >
                    <h2 class="mb-1 font-medium">Print notes</h2>
                    <ul class="list-inside list-disc space-y-1 text-xs">
                        <li v-for="n in notes" :key="n">{{ n }}</li>
                    </ul>
                </section>

                <section v-if="errors.length" class="rounded-xl border border-destructive/40 p-3 text-sm text-destructive" role="alert">
                    <ul class="list-inside list-disc">
                        <li v-for="e in errors" :key="e">{{ e }}</li>
                    </ul>
                </section>

                <section class="space-y-3 rounded-xl border p-4">
                    <div class="flex flex-wrap gap-3">
                        <Button v-if="editable" :disabled="!canSave" @click="save">{{
                            saveForm.processing ? 'Saving…' : clicker ? 'Save changes' : 'Save design'
                        }}</Button>
                        <Button v-if="clicker" variant="outline" :disabled="busy" @click="duplicate">{{
                            editable ? 'Duplicate' : 'Duplicate to edit'
                        }}</Button>
                        <Button v-if="clicker && clicker.status !== 'building'" variant="outline" :disabled="busy" @click="remove">Delete</Button>
                    </div>

                    <p v-if="!clicker" class="text-xs text-muted-foreground">
                        Designing is free. Save your design, then unlock the print files for {{ peso(unlock_cost) }}.
                    </p>

                    <div v-if="clicker && canUnlock" class="space-y-2 border-t pt-3">
                        <p v-if="clicker.status === 'failed'" class="text-sm text-destructive">
                            {{ clicker.error ?? 'We could not build your files.' }} You were refunded. You can try again.
                        </p>
                        <p class="text-sm">
                            Download the <strong>3MF</strong> (each colour as its own part) and a <strong>zip of one STL per colour</strong> for
                            <strong>{{ peso(unlock_cost) }}</strong
                            >. You pay once for this design and can download both as often as you like.
                        </p>
                        <p v-if="unlockForm.errors.unlock" class="text-sm text-destructive" role="alert">{{ unlockForm.errors.unlock }}</p>
                        <p class="text-xs text-muted-foreground">Your balance: {{ peso(balance) }}</p>
                        <p v-if="dirty" class="text-xs text-muted-foreground">Save your changes first, so the files match what you see.</p>
                        <Button v-if="affordable" :disabled="busy || dirty" @click="unlock">
                            {{ unlockForm.processing ? 'Unlocking…' : `Unlock downloads (${peso(unlock_cost)})` }}
                        </Button>
                        <Button v-else as-child><Link href="/credits">Add balance to download</Link></Button>
                    </div>

                    <div v-if="clicker && clicker.status === 'building'" class="space-y-1 border-t pt-3 text-sm">
                        <p>Building your print files… this usually takes a few seconds.</p>
                        <p v-if="polling.slow.value" class="text-xs text-muted-foreground">
                            This is taking longer than expected. If it doesn't finish soon you are refunded automatically.
                        </p>
                    </div>

                    <div v-if="clicker && clicker.urls.threemf && clicker.urls.stl_zip" class="space-y-2 border-t pt-3">
                        <p class="text-sm font-medium">Your print files are ready</p>
                        <div class="flex flex-wrap gap-3">
                            <Button as-child><a :href="clicker.urls.threemf">Download 3MF</a></Button>
                            <Button variant="outline" as-child><a :href="clicker.urls.stl_zip">Download STL zip</a></Button>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            The 3MF opens as a multi-colour project in Creality Print, Bambu Studio and PrusaSlicer. Your slicer may ask which
                            filament to use for each colour.
                        </p>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
