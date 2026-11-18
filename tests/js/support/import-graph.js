import fs from 'node:fs';
import path from 'node:path';

const IMPORT = /\b(?:import|export)\s*(?:[\w${},*\s]+?\s+from\s*)?\(?\s*['"]([^'"]+)['"]/g;
const SOURCE = /\.(js|jsx|vue)$/;

function resolveImport(from, spec, root) {
    let base;
    if (spec.startsWith('@/')) {
        base = path.join('resources/admin', spec.slice(2)); // the '@' alias in vite.config.mjs
    } else if (spec.startsWith('.')) {
        base = path.join(path.dirname(from), spec);
    } else {
        return null;
    }

    return ['', '.js', '.vue', '.jsx', '/index.js']
        .map(ext => base + ext)
        .find(file => SOURCE.test(file) && fs.existsSync(path.join(root, file))) || null;
}

/**
 * Repo-relative paths of every local module an entry pulls in, the entry included.
 */
export function importGraph(entry, root = process.cwd()) {
    const seen = new Set();

    const visit = file => {
        if (seen.has(file)) {
            return;
        }
        seen.add(file);

        const source = fs.readFileSync(path.join(root, file), 'utf8');
        for (const [, spec] of source.matchAll(IMPORT)) {
            const dep = resolveImport(file, spec, root);
            if (dep) {
                visit(dep);
            }
        }
    };

    visit(entry);

    return [...seen];
}
