import fs from 'fs';
import path from 'path';

function jsonToPhpArray(obj, indentLevel = 1) {
    const indent = '    '.repeat(indentLevel);

    if (Array.isArray(obj)) {
        return '[\n'
            + obj.map((value) => `${indent}${valueToPhp(value, indentLevel + 1)}`).join(',\n')
            + '\n' + '    '.repeat(indentLevel - 1) + ']';
    }

    if (typeof obj === 'object' && obj !== null) {
        return '[\n'
            + Object.entries(obj)
                .map(([key, value]) => `${indent}'${key}' => ${valueToPhp(value, indentLevel + 1)}`)
                .join(',\n')
            + '\n' + '    '.repeat(indentLevel - 1) + ']';
    }

    return valueToPhp(obj, indentLevel);
}

function valueToPhp(value, indentLevel) {
    if (typeof value === 'string') {
        return `'${value.replace(/'/g, "\\'")}'`;
    }

    if (typeof value === 'number') {
        return String(value);
    }

    if (typeof value === 'boolean') {
        return value ? 'true' : 'false';
    }

    if (value === null) {
        return 'null';
    }

    if (Array.isArray(value) || typeof value === 'object') {
        return jsonToPhpArray(value, indentLevel);
    }

    return 'null';
}

export function manifestPhpConfigPlugin({ rootDir, manifestPath, phpConfigPath }) {
    return {
        name: 'manifest-php-config',
        writeBundle() {
            const manifestFullPath = path.resolve(rootDir, manifestPath);

            if (!fs.existsSync(manifestFullPath)) {
                return;
            }

            const manifestContent = JSON.parse(fs.readFileSync(manifestFullPath, 'utf8'));
            const phpArray = jsonToPhpArray(manifestContent);
            const configPath = path.resolve(rootDir, phpConfigPath);

            if (!fs.existsSync(configPath)) {
                fs.writeFileSync(configPath, '<?php return [];', 'utf8');
            }

            fs.writeFileSync(configPath, '<?php return ' + phpArray + ';', 'utf8');
            console.log('✅ Manifest array injected into ' + phpConfigPath);
        },
    };
}
