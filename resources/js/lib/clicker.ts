import { analyzeArtwork, bodyProxyPart, buildParts, paletteFromArtwork, prepareArtwork } from '@clicker/geometry.js';

/** Typed access to the shared clicker geometry module (plain JavaScript, also run by the server build). */

export type ClickerColor = { hex: string; height_mm: number; enabled: boolean };

export type ClickerSettings = {
    colors: ClickerColor[];
    scale: number;
    offset_x: number;
    offset_y: number;
    outline_mm: number;
    outline_hex: string;
    base_shape: BaseShape;
    hollow_cap: boolean;
    remove_background: boolean;
    /** How far the cap sits inside the base at rest, as a fraction of the cap height (0.5 to 1). */
    cap_insertion: number;
};

export type BaseShape = 'square' | 'circle' | 'trace';

export type BodyConfig = {
    cap: { shape: 'rect' | 'rounded' | 'circle'; width: number; height: number; corner_radius: number; top_z: number; inset: number };
    artwork_area: { cx: number; cy: number; width: number; height: number };
    max_layer_height: number;
    min_feature: number;
    switch?: string;
    /** Set for bodies whose mesh is generated in the browser (a Cherry MX keycap). */
    generator?: string;
    keycap?: Record<string, number | string>;
};

/** RGBA pixels, as from canvas getImageData(). */
export type Pixels = { data: Uint8ClampedArray; width: number; height: number };

export type Part = { kind: 'colour' | 'outline' | 'body' | 'base'; name: string; hex: string; positions: Float32Array; indices: Uint32Array };

/** The slow half of the pipeline. Opaque to the editor except for the print notes. */
export type Analysis = { notes: string[]; blockers: string[] };

export const analyze = (image: Pixels, settings: ClickerSettings, body: BodyConfig): Analysis =>
    analyzeArtwork(image, settings, body, 'preview') as unknown as Analysis;

export const extrude = (analysis: Analysis, settings: ClickerSettings, body: BodyConfig): Part[] =>
    buildParts(analysis as never, settings, body) as Part[];

export const bodyProxy = (cap: BodyConfig['cap']): Part => bodyProxyPart(cap) as Part;

/** The artwork as the pipeline sees it (a plain background made transparent when that setting applies). */
export const prepared = (image: Pixels, settings: ClickerSettings, body: BodyConfig): Pixels => prepareArtwork(image, settings, body) as Pixels;

/** Suggest colours from the prepared artwork, so a removed background does not take a colour slot. */
export const paletteFor = (image: Pixels, count: number, settings: ClickerSettings, body: BodyConfig): string[] =>
    paletteFromArtwork(image, count, settings, body) as string[];
