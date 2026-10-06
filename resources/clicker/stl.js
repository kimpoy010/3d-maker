/** Binary STL writer and a reader for body files (binary or ASCII). */

/** @param {{positions: Float32Array, indices: Uint32Array}} mesh */
export function encodeStl({ positions, indices }) {
    const triangles = indices.length / 3;
    const bytes = new Uint8Array(84 + 50 * triangles);
    const view = new DataView(bytes.buffer);
    const header = 'Clicker maker';
    for (let i = 0; i < header.length; i++) bytes[i] = header.charCodeAt(i);
    view.setUint32(80, triangles, true);

    let offset = 84;
    for (let t = 0; t < triangles; t++) {
        const [a, b, c] = [indices[3 * t], indices[3 * t + 1], indices[3 * t + 2]];
        const v = [a, b, c].map((i) => [positions[3 * i], positions[3 * i + 1], positions[3 * i + 2]]);
        const u = [v[1][0] - v[0][0], v[1][1] - v[0][1], v[1][2] - v[0][2]];
        const w = [v[2][0] - v[0][0], v[2][1] - v[0][1], v[2][2] - v[0][2]];
        const n = [u[1] * w[2] - u[2] * w[1], u[2] * w[0] - u[0] * w[2], u[0] * w[1] - u[1] * w[0]];
        const length = Math.hypot(...n) || 1;
        for (const value of [n[0] / length, n[1] / length, n[2] / length, ...v[0], ...v[1], ...v[2]]) {
            view.setFloat32(offset, value, true);
            offset += 4;
        }
        offset += 2; // attribute byte count
    }

    return bytes;
}

/**
 * Parse an STL file and weld identical vertices so the result is an indexed mesh.
 * @param {Uint8Array} bytes
 * @returns {{positions: Float32Array, indices: Uint32Array}}
 */
export function parseStl(bytes) {
    const soup = [];
    const view = new DataView(bytes.buffer, bytes.byteOffset, bytes.byteLength);
    const binaryCount = bytes.length >= 84 ? view.getUint32(80, true) : -1;

    if (binaryCount >= 0 && bytes.length === 84 + 50 * binaryCount) {
        for (let t = 0; t < binaryCount; t++) {
            const base = 84 + 50 * t + 12;
            for (let k = 0; k < 9; k++) soup.push(view.getFloat32(base + 4 * k, true));
        }
    } else {
        const text = new TextDecoder().decode(bytes);
        for (const match of text.matchAll(/vertex\s+(\S+)\s+(\S+)\s+(\S+)/g)) soup.push(Number(match[1]), Number(match[2]), Number(match[3]));
    }

    if (soup.length === 0 || soup.length % 9 !== 0 || soup.some((v) => !Number.isFinite(v))) throw new Error('The STL file is not valid.');

    const positions = [];
    const indices = [];
    const seen = new Map();
    for (let i = 0; i < soup.length; i += 3) {
        const key = `${Math.round(soup[i] * 1e4)},${Math.round(soup[i + 1] * 1e4)},${Math.round(soup[i + 2] * 1e4)}`;
        let index = seen.get(key);
        if (index === undefined) {
            index = positions.length / 3;
            seen.set(key, index);
            positions.push(soup[i], soup[i + 1], soup[i + 2]);
        }
        indices.push(index);
    }

    return { positions: new Float32Array(positions), indices: new Uint32Array(indices) };
}
