import earcut from 'earcut';
import { signedArea } from './trace.js';

/**
 * Convert traced pixel polygons to millimetres (y up). Outer rings come out counter-clockwise and holes
 * clockwise, so the solid is always on the left of travel.
 * `frame` = {left, top, mmPerPx}: where the pixel grid's top-left corner sits, in mm.
 */
export function toMm(polygons, frame) {
    const convert = (ring) => ring.map(([x, y]) => [frame.left + x * frame.mmPerPx, frame.top - y * frame.mmPerPx]);
    const orient = (ring, counterClockwise) => (signedArea(ring) > 0 === counterClockwise ? ring : ring.slice().reverse());

    return polygons.map(({ outer, holes }) => ({
        outer: orient(convert(outer), true),
        holes: holes.map((hole) => orient(convert(hole), false)),
    }));
}

/**
 * Extrude polygons (mm, y up, outer CCW, holes CW) from z0 to z1 into a closed, consistently wound mesh.
 * @returns {{positions: Float32Array, indices: Uint32Array}}
 */
export function extrudePolygons(polygons, z0, z1) {
    const positions = [];
    const indices = [];

    for (const polygon of polygons) {
        const rings = [polygon.outer, ...polygon.holes];
        const flat = [];
        const holeStarts = [];
        const ringStarts = [];
        let count = 0;

        rings.forEach((ring, ringIndex) => {
            if (ringIndex > 0) holeStarts.push(count);
            ringStarts.push(count);
            for (const [x, y] of ring) {
                flat.push(x, y);
                count++;
            }
        });

        const base = positions.length / 3;
        for (let i = 0; i < count; i++) positions.push(flat[2 * i], flat[2 * i + 1], z0);
        for (let i = 0; i < count; i++) positions.push(flat[2 * i], flat[2 * i + 1], z1);

        const triangles = earcut(flat, holeStarts, 2);
        for (let t = 0; t < triangles.length; t += 3) {
            const a = triangles[t];
            let b = triangles[t + 1];
            let c = triangles[t + 2];
            const cross =
                (flat[2 * b] - flat[2 * a]) * (flat[2 * c + 1] - flat[2 * a + 1]) - (flat[2 * b + 1] - flat[2 * a + 1]) * (flat[2 * c] - flat[2 * a]);
            if (cross < 0) [b, c] = [c, b];
            indices.push(base + count + a, base + count + b, base + count + c); // top, facing up
            indices.push(base + a, base + c, base + b); // bottom, facing down
        }

        rings.forEach((ring, ringIndex) => {
            const start = ringStarts[ringIndex];
            for (let i = 0; i < ring.length; i++) {
                const a = start + i;
                const b = start + ((i + 1) % ring.length);
                indices.push(base + a, base + b, base + count + b, base + a, base + count + b, base + count + a);
            }
        });
    }

    return { positions: new Float32Array(positions), indices: new Uint32Array(indices) };
}

/** True when every edge is shared by exactly two triangles, once in each direction (a closed, consistently wound mesh). */
export function isClosedManifold({ indices }) {
    const directed = new Map();
    for (let t = 0; t < indices.length; t += 3) {
        for (let k = 0; k < 3; k++) {
            const a = indices[t + k];
            const b = indices[t + ((k + 1) % 3)];
            const key = `${a}>${b}`;
            directed.set(key, (directed.get(key) ?? 0) + 1);
        }
    }
    for (const [key, count] of directed) {
        if (count !== 1) return false;
        const [a, b] = key.split('>');
        if (directed.get(`${b}>${a}`) !== 1) return false;
    }

    return directed.size > 0;
}

/** Signed volume in mm^3 (positive for outward-facing triangles). */
export function meshVolume({ positions, indices }) {
    let volume = 0;
    for (let t = 0; t < indices.length; t += 3) {
        const [ax, ay, az] = [positions[3 * indices[t]], positions[3 * indices[t] + 1], positions[3 * indices[t] + 2]];
        const [bx, by, bz] = [positions[3 * indices[t + 1]], positions[3 * indices[t + 1] + 1], positions[3 * indices[t + 1] + 2]];
        const [cx, cy, cz] = [positions[3 * indices[t + 2]], positions[3 * indices[t + 2] + 1], positions[3 * indices[t + 2] + 2]];
        volume += (ax * (by * cz - bz * cy) - ay * (bx * cz - bz * cx) + az * (bx * cy - by * cx)) / 6;
    }

    return volume;
}

/** Bounding box [minX, minY, minZ, maxX, maxY, maxZ] of a mesh. */
export function bounds({ positions }) {
    const box = [Infinity, Infinity, Infinity, -Infinity, -Infinity, -Infinity];
    for (let i = 0; i < positions.length; i += 3) {
        for (let k = 0; k < 3; k++) {
            if (positions[i + k] < box[k]) box[k] = positions[i + k];
            if (positions[i + k] > box[k + 3]) box[k + 3] = positions[i + k];
        }
    }

    return box;
}
