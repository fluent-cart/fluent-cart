import fs from 'fs';
import path from 'path';

export function moveManifestPlugin({ rootDir, manifestPath }) {
    let viteConfig;

    return {
        name: 'move-manifest',
        configResolved(resolvedConfig) {
            viteConfig = resolvedConfig;
        },
        writeBundle() {
            const outDir = viteConfig.build.outDir;
            const manifestSrc = path.join(outDir, '.vite', 'manifest.json');
            const manifestDest = path.resolve(rootDir, manifestPath);
            const viteDir = path.join(outDir, '.vite');

            if (!fs.existsSync(manifestSrc)) {
                return;
            }

            fs.renameSync(manifestSrc, manifestDest);

            if (fs.existsSync(viteDir) && fs.readdirSync(viteDir).length === 0) {
                fs.rmSync(viteDir, { recursive: true });
            }
        },
    };
}
