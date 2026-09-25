import fs from 'fs';
import path from 'path';
import {transformWithEsbuild} from 'vite';

// Vendor builds that already ship minified are left exactly as published.
const PREMINIFIED = /\.min\.(js|css)$/;

function walkFiles(dirPath) {
    return fs.readdirSync(dirPath, { withFileTypes: true }).flatMap((entry) => {
        const fullPath = path.join(dirPath, entry.name);

        if (entry.isDirectory()) {
            return walkFiles(fullPath);
        }

        return [fullPath];
    });
}

// Minify the .js/.css files in place. Names never change: PHP enqueues these
// by path (Vite::enqueueStaticScript/Style), not through the manifest.
export async function minifyStaticLibDir(dirPath) {
    if (!fs.existsSync(dirPath)) {
        return;
    }

    const files = walkFiles(dirPath).filter((filePath) => {
        const ext = path.extname(filePath);

        return (ext === '.js' || ext === '.css') && !PREMINIFIED.test(path.basename(filePath));
    });

    await Promise.all(files.map(async (filePath) => {
        const source = fs.readFileSync(filePath, 'utf8');
        const result = await transformWithEsbuild(source, filePath, {
            loader: path.extname(filePath).slice(1),
            minify: true,
            // Keep /*! ... */ licence banners.
            legalComments: 'inline',
        });

        if (result.code.length < source.length) {
            fs.writeFileSync(filePath, result.code);
        }
    }));
}

// resources/public/lib is copied verbatim by vite-plugin-static-copy, which
// runs in writeBundle; closeBundle fires after every writeBundle has finished.
// Build-only: the dev server keeps serving the readable files from resources/.
export function minifyStaticLibPlugin({ libDir }) {
    let viteConfig;

    return {
        name: 'minify-static-lib',
        apply: 'build',
        configResolved(resolvedConfig) {
            viteConfig = resolvedConfig;
        },
        async closeBundle() {
            await minifyStaticLibDir(path.resolve(viteConfig.build.outDir, libDir));
        },
    };
}
