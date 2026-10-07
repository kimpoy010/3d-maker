<script setup lang="ts">
import type { Part } from '@/lib/clicker';
import * as THREE from 'three';
import { OrbitControls } from 'three/examples/jsm/controls/OrbitControls.js';
import { GLTFLoader } from 'three/examples/jsm/loaders/GLTFLoader.js';
import { toCreasedNormals } from 'three/examples/jsm/utils/BufferGeometryUtils.js';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

// Either `src` (a GLB to download) or `parts` (meshes built in the browser, millimetres, Z up).
const props = withDefaults(defineProps<{ src?: string; parts?: Part[]; autoRotate?: boolean; defaultLook?: 'textured' | 'clay' }>(), {
    autoRotate: true,
    defaultLook: 'clay',
});

const container = ref<HTMLDivElement | null>(null);
const state = ref<'loading' | 'ready' | 'error'>('loading');
const rotating = ref(props.autoRotate);
const preset = ref<'studio' | 'soft'>('soft');
const look = ref<'textured' | 'clay'>(props.defaultLook);

let renderer: THREE.WebGLRenderer | null = null;
let scene: THREE.Scene | null = null;
let camera: THREE.PerspectiveCamera | null = null;
let controls: OrbitControls | null = null;
let model: THREE.Object3D | null = null;
let frame = 0;
let loadId = 0;
let resizeObserver: ResizeObserver | null = null;
let lights: THREE.Light[] = [];
// Parts mode re-builds the model on every edit; the camera is framed once so the view does not jump.
let framedOnce = false;
let framedFor = ''; // the size of the first part (the cap) the camera was framed for
const centerOffset = new THREE.Vector3();
// One plain matte material shared by every mesh in clay view; the painted ones are kept on the mesh.
const clayMaterial = new THREE.MeshStandardMaterial({ color: 0x6e6e6e, roughness: 0.92, metalness: 0 });

function makeDirectional(color: number, intensity: number, x: number, y: number, z: number) {
    const light = new THREE.DirectionalLight(color, intensity);
    light.position.set(x, y, z);
    return light;
}

function applyPreset() {
    if (!scene) return;
    lights.forEach((l) => scene!.remove(l));
    lights =
        preset.value === 'studio'
            ? [new THREE.HemisphereLight(0xffffff, 0x666677, 1.1), makeDirectional(0xffffff, 2.2, 3, 5, 4)]
            : [new THREE.HemisphereLight(0xffffff, 0xddddee, 2.2), makeDirectional(0xfff2e0, 0.6, -2, 3, 2)];
    lights.forEach((l) => scene!.add(l));
}

function applyLook() {
    model?.traverse((node) => {
        const mesh = node as THREE.Mesh;
        if (!mesh.isMesh) return;
        mesh.userData.original ??= mesh.material;
        mesh.material = look.value === 'clay' ? clayMaterial : mesh.userData.original;
    });
}

function resize() {
    if (!container.value || !renderer || !camera) return;
    const { clientWidth: w, clientHeight: h } = container.value;
    if (w === 0 || h === 0) return;
    renderer.setSize(w, h);
    camera.aspect = w / h;
    camera.updateProjectionMatrix();
}

/** `elevated` (parts mode) looks down at about 40 degrees so the top face and artwork show; GLB framing is unchanged. */
function frameModel(object: THREE.Object3D, elevated = false) {
    const box = new THREE.Box3().setFromObject(object);
    const size = box.getSize(new THREE.Vector3());
    const center = box.getCenter(new THREE.Vector3());
    object.position.sub(center);

    const maxDim = Math.max(size.x, size.y, size.z) || 1;
    camera!.near = maxDim / 100;
    camera!.far = maxDim * 100;
    if (elevated) {
        // Parts mode shows the cap with the base below it: pull back so the whole clicker has room around it.
        const pullBack = 1.3;
        camera!.position.set(0, maxDim * 1.4 * pullBack, maxDim * 1.6 * pullBack);
        camera!.far = camera!.position.length() * 100;
    } else camera!.position.set(0, maxDim * 0.3, maxDim * 2.2);
    camera!.updateProjectionMatrix();
    controls!.target.set(0, 0, 0);
    controls!.update();
}

function disposeModel() {
    if (!model || !scene) return;
    scene.remove(model);
    model.traverse((node) => {
        const mesh = node as THREE.Mesh;
        if (!mesh.isMesh) return;
        mesh.geometry.dispose();
        // Dispose the model's own materials, not the shared clay one (released on unmount).
        const own = mesh.userData.original ?? mesh.material;
        (Array.isArray(own) ? own : [own]).forEach((m: THREE.Material) => {
            for (const value of Object.values(m)) {
                if (value instanceof THREE.Texture) value.dispose();
            }
            m.dispose();
        });
    });
    model = null;
}

function load(url: string) {
    if (!scene) return;
    const id = ++loadId;
    state.value = 'loading';
    disposeModel();
    new GLTFLoader().load(
        url,
        (gltf) => {
            if (id !== loadId || !scene) return;
            model = gltf.scene;
            scene.add(model);
            applyLook();
            frameModel(model);
            state.value = 'ready';
        },
        undefined,
        () => {
            if (id !== loadId) return;
            state.value = 'error';
        },
    );
}

function showParts(list: Part[]) {
    if (!scene) return;
    loadId++; // cancels any download still in flight
    disposeModel();

    if (list.length === 0) {
        // Nothing to draw: an empty bounding box would give NaN positions, so start framing afresh next time.
        framedOnce = false;
        return;
    }

    const group = new THREE.Group();
    for (const part of list) {
        const indexed = new THREE.BufferGeometry();
        indexed.setAttribute('position', new THREE.BufferAttribute(part.positions, 3));
        indexed.setIndex(new THREE.BufferAttribute(part.indices, 1));
        // Shared vertices would smooth the hard edges of a slab or an extrusion; creased normals keep them sharp.
        const geometry = toCreasedNormals(indexed, (40 * Math.PI) / 180);
        indexed.dispose();
        group.add(new THREE.Mesh(geometry, new THREE.MeshStandardMaterial({ color: part.hex, roughness: 0.65, metalness: 0 })));
    }
    group.rotation.x = -Math.PI / 2; // parts are built with Z up; the scene is Y up

    model = group;
    scene.add(group);
    applyLook();

    // Key the framing on the keycap/slab body (the last part of a generated body), not on a colour layer.
    const bodyIndex = Math.max(
        0,
        list.findIndex((p) => p.kind === 'body'),
    );
    const first = new THREE.Box3().setFromObject(group.children[bodyIndex]).getSize(new THREE.Vector3());
    const sizeKey = `${first.x.toFixed(2)},${first.y.toFixed(2)},${first.z.toFixed(2)}`;

    if (framedOnce && sizeKey === framedFor) {
        group.position.copy(centerOffset);
    } else {
        frameModel(group, true);
        centerOffset.copy(group.position);
        framedOnce = true;
        framedFor = sizeKey;
    }
    state.value = 'ready';
}

/** A small PNG (at most 480 px) of what the viewer shows right now, or null when nothing can be drawn. */
function snapshot(): Promise<Blob | null> {
    return new Promise((resolve) => {
        if (!renderer || !scene || !camera || state.value !== 'ready') return resolve(null);
        renderer.render(scene, camera); // render and copy in the same task so the buffer is still filled

        const source = renderer.domElement;
        const scale = Math.min(1, 480 / Math.max(source.width, source.height));
        const copy = document.createElement('canvas');
        copy.width = Math.max(1, Math.round(source.width * scale));
        copy.height = Math.max(1, Math.round(source.height * scale));
        copy.getContext('2d')?.drawImage(source, 0, 0, copy.width, copy.height);
        copy.toBlob((blob) => resolve(blob), 'image/png');
    });
}

defineExpose({ snapshot });

function tick() {
    frame = requestAnimationFrame(tick);
    controls?.update();
    if (renderer && scene && camera) renderer.render(scene, camera);
}

onMounted(() => {
    if (!container.value) return;

    try {
        renderer = new THREE.WebGLRenderer({ antialias: true, alpha: true });
    } catch {
        // No WebGL (blocked, unsupported, or out of contexts): show the error overlay.
        renderer = null;
        state.value = 'error';
        return;
    }
    renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));
    container.value.appendChild(renderer.domElement);

    scene = new THREE.Scene();
    camera = new THREE.PerspectiveCamera(40, 1, 0.01, 100);
    controls = new OrbitControls(camera, renderer.domElement);
    controls.enableDamping = true;
    controls.autoRotate = rotating.value;
    controls.autoRotateSpeed = 1.5;

    applyPreset();
    resize();
    resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(container.value);
    if (props.parts) showParts(props.parts);
    else if (props.src) load(props.src);
    tick();
});

watch(
    () => props.src,
    (url) => {
        if (url) load(url);
    },
);
watch(
    () => props.parts,
    (list) => {
        if (list) showParts(list);
    },
);
watch(preset, applyPreset);
watch(look, applyLook);
watch(rotating, (value) => {
    if (controls) controls.autoRotate = value;
});

onBeforeUnmount(() => {
    loadId++;
    cancelAnimationFrame(frame);
    resizeObserver?.disconnect();
    disposeModel();
    clayMaterial.dispose();
    controls?.dispose();
    renderer?.dispose();
    renderer?.forceContextLoss();
    renderer?.domElement.remove();
    renderer = scene = camera = controls = null;
});
</script>

<template>
    <div class="relative aspect-square w-full overflow-hidden rounded-xl border bg-muted/40">
        <div ref="container" class="absolute inset-0" />

        <div v-if="state === 'loading'" class="absolute inset-0 flex items-center justify-center text-sm text-muted-foreground">Loading model…</div>
        <div v-else-if="state === 'error'" class="absolute inset-0 flex items-center justify-center px-6 text-center text-sm text-destructive">
            This model could not be loaded.
        </div>

        <div v-if="state === 'ready'" class="absolute bottom-3 left-3 flex gap-2 text-xs">
            <button type="button" class="rounded-md border bg-background/80 px-2 py-1 backdrop-blur" @click="rotating = !rotating">
                {{ rotating ? 'Pause' : 'Rotate' }}
            </button>
            <button
                type="button"
                class="rounded-md border bg-background/80 px-2 py-1 backdrop-blur"
                @click="preset = preset === 'studio' ? 'soft' : 'studio'"
            >
                Light: {{ preset === 'studio' ? 'Studio' : 'Soft' }}
            </button>
            <button
                type="button"
                class="rounded-md border bg-background/80 px-2 py-1 backdrop-blur"
                @click="look = look === 'clay' ? 'textured' : 'clay'"
            >
                View: {{ look === 'clay' ? 'Clay' : 'Textured' }}
            </button>
        </div>
    </div>
</template>
