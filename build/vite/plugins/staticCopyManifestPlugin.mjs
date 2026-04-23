import fs from 'fs';
import path from 'path';

function normalizePath(filePath) {
    return filePath.split(path.sep).join('/');
}

function walkFiles(dirPath) {
    return fs.readdirSync(dirPath, { withFileTypes: true }).flatMap((entry) => {
        const fullPath = path.join(dirPath, entry.name);

        if (entry.isDirectory()) {
            return walkFiles(fullPath);
        }

        return [fullPath];
    });
}

function getManifestEntry(rootDir, sourcePath, target) {
    const relativeSource = normalizePath(path.relative(rootDir, sourcePath));
    const name = path.parse(sourcePath).name;

    if (fs.statSync(sourcePath).isFile()) {
        return {
            src: relativeSource,
            file: normalizePath(path.posix.join(target.dest || '', path.basename(sourcePath))),
            name,
        };
    }

    const sourceRootName = path.basename(sourcePath);

    return walkFiles(sourcePath).map((filePath) => {
        const relativeCopied = normalizePath(path.relative(sourcePath, filePath));

        return {
            src: normalizePath(path.relative(rootDir, filePath)),
            file: normalizePath(path.posix.join(target.dest || '', sourceRootName, relativeCopied)),
            name: path.parse(filePath).name,
        };
    });
}

function getCopiedManifestEntries(rootDir, targets) {
    return targets.flatMap((target) => {
        const sourcePath = path.resolve(rootDir, target.src);

        if (!fs.existsSync(sourcePath)) {
            return [];
        }

        return getManifestEntry(rootDir, sourcePath, target);
    });
}

export function staticCopyManifestPlugin({ rootDir, manifestPath, staticCopyTargets }) {
    return {
        name: 'static-copy-manifest',
        writeBundle() {
            const manifestDest = path.resolve(rootDir, manifestPath);

            if (!fs.existsSync(manifestDest)) {
                return;
            }

            const manifest = JSON.parse(fs.readFileSync(manifestDest, 'utf8'));
            const copiedManifestEntries = getCopiedManifestEntries(rootDir, staticCopyTargets);

            copiedManifestEntries.forEach((entry) => {
                manifest[entry.src] = {
                    file: entry.file,
                    name: entry.name,
                    src: entry.src,
                    isEntry: true,
                };
            });

            fs.writeFileSync(manifestDest, JSON.stringify(manifest, null, 2));
        },
    };
}
