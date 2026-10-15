// Fails the build when any chunk imports a shared `module-*` entry. Those
// entries are import-map targets: if core code imports one too, the browser
// sees it under two URLs once a host rewrites asset URLs, and loads two Vues.
export function sharedModuleGuardPlugin({prefix = 'module-'} = {}) {
    return {
        name: 'fct-shared-module-guard',
        apply: 'build',
        generateBundle(_, bundle) {
            const shared = new Set(
                Object.values(bundle)
                    .filter((chunk) => chunk.type === 'chunk' && chunk.isEntry && chunk.fileName.startsWith(prefix))
                    .map((chunk) => chunk.fileName)
            );

            const offenders = [];
            for (const chunk of Object.values(bundle)) {
                if (chunk.type !== 'chunk') {
                    continue;
                }
                for (const imported of [...chunk.imports, ...chunk.dynamicImports]) {
                    if (shared.has(imported)) {
                        offenders.push(`${chunk.fileName} -> ${imported}`);
                    }
                }
            }

            if (offenders.length) {
                this.error('Shared module entries must only be reached through the import map:\n' + offenders.join('\n'));
            }
        }
    };
}
