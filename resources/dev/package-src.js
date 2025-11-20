const fs = require('fs');
const path = require('path');
const {execSync} = require('child_process');

/**
 * Get the src folder(s) from a Composer package.
 *
 * @param {string} packageName Example: "openspout/openspout"
 * @param {string} projectRoot Your WP plugin root (default: current project)
 * @returns {string[]} List of absolute paths to src folders
 */
function getPackageSrcFolders(packageName, projectRoot = path.resolve(__dirname, '..')) {
    const vendorDir = path.join(projectRoot, 'vendor');

    const [vendor, pkg] = packageName.split('/');
    const packageRoot = path.join(vendorDir, vendor, pkg);


    const composerFile = path.join(packageRoot, 'composer.json');


    if (!fs.existsSync(composerFile)) {
        throw new Error(`composer.json not found for package: ${packageName}`);
    }

    const data = JSON.parse(fs.readFileSync(composerFile, 'utf8'));

    const autoload = data.autoload || {};
    const psr4 = autoload['psr-4'] || {};


    const resultPaths = [];

    for (const prefix in psr4) {
        let relPath = psr4[prefix];

        // PSR-4 can be a string OR array
        if (Array.isArray(relPath)) {
            relPath.forEach(p => {
                const abs = path.join(packageRoot, p);
                if (fs.existsSync(abs)) resultPaths.push(abs);
            });
        } else {
            const abs = path.join(packageRoot, relPath);
            if (fs.existsSync(abs)) resultPaths.push(abs);
        }
    }

    return resultPaths;
}


// ----------- TEST USAGE -----------
if (require.main === module) {
    const pkg = process.argv[2];
    if (!pkg) {
        console.log('Usage: node get-package-src.js vendor/package');
        process.exit(1);
    }

    try {
        const projectRoot = path.resolve(__dirname, '..', '../')
        const srcPaths = getPackageSrcFolders(pkg, projectRoot);
        console.log('Detected src folders:');
        srcPaths.forEach(p => console.log(' - ' + p));
        const newNamespace = 'FluentCart';

        writeScoperFile(srcPaths, 'dev/scoper.inc.php', newNamespace);

        const scoperOutputDir = `build-scoped/vendor/scoped`;
        runScoper(scoperOutputDir)
        copyDirReplace('dev/' + scoperOutputDir, srcPaths[0]);


        const installedPathJson = getInstalledJsonPath(projectRoot);
        updateNamespace(installedPathJson, pkg, newNamespace, null, projectRoot, srcPaths[0]);

        execSync('composer dump', {
            cwd: projectRoot,       // run INSIDE dev/
            stdio: 'inherit',
            shell: true
        });

    } catch (e) {
        console.error(e.message);
    }
}

/**
 * Update the PSR-4 namespace for a specific package in installed.json
 *
 * @param file
 * @param {string} packageName e.g. "fakerphp/faker"
 * @param {string} newNamespace e.g. "FluentCart\\Faker\\"
 * @param {string} newPath e.g. "vendor/fakerphp/faker"
 * @param {string} projectRoot
 */
function updateNamespace(file, packageName, newNamespace, newPath, projectRoot = process.cwd(), srcPath) {

    const json = JSON.parse(fs.readFileSync(file, 'utf8'));

    // Composer v2 structure:
    const packages = json.packages || json;

    let changed = false;

    for (const pkg of packages) {
        if (pkg.name === packageName) {

            if (!pkg.autoload) pkg.autoload = {};
            if (!pkg.autoload['psr-4']) pkg.autoload['psr-4'] = {};

            // pkg.autoload['psr-4'] = {
            //     [newNamespace]: newPath
            // };

            const newPsr = {};

            for (const [key, value] of Object.entries(pkg.autoload['psr-4'])) {
                let psrKey = key;
                let psrValue = value;

                if (!psrKey.startsWith(newNamespace)) {
                    psrKey = newNamespace + '\\' + psrKey;
                }
                newPsr[psrKey] = psrValue;
            }
            pkg.autoload['psr-4'] = newPsr;

            changed = true;
            console.log(`✔ Updated namespace for ${packageName}`);
        }
    }

    if (changed) {
        fs.writeFileSync(file, JSON.stringify(json, null, 2));
        console.log(`✔ Saved updated installed.json`);
    } else {
        console.log(`⚠ No matching package found: ${packageName}`);
    }
}

function getInstalledJsonPath(projectRoot = process.cwd()) {
    const file = path.join(projectRoot, 'vendor', 'composer', 'installed.json');

    if (!fs.existsSync(file)) {
        throw new Error('installed.json not found at: ' + file);
    }

    return file;
}

/**
 * Generate scoper.inc.php using given src paths.
 *
 * @param {string[]} srcPaths  Absolute paths to source folders
 * @param {string} outputPath  Path to write scoper.inc.php
 */
function writeScoperFile(srcPaths, outputPath, newNamespace) {
    if (!Array.isArray(srcPaths)) {
        throw new Error("srcPaths must be an array");
    }

    // Convert JS array → PHP array format
    const phpArray = srcPaths
        .map(p => `'${p.replace(/\\/g, '\\\\')}'`)
        .join(', ');

    const phpContent = `<?php

use Isolated\\Symfony\\Component\\Finder\\Finder;

/**
 * Build Finder entries from an array of src folder paths.
 *
 * @param array \$srcPaths
 * @return array
 */
function buildFinders(array \$srcPaths)
{
    \$finders = [];

    foreach (\$srcPaths as \$src) {
        if (is_dir(\$src)) {
            \$finders[] = Finder::create()
                ->files()
                ->in(\$src)
                ->name('*.php');
        }
    }

    return \$finders;
}

\$srcPaths = [${phpArray}];

return [
    'prefix' => '${newNamespace}',

    'finders' => buildFinders(\$srcPaths),

    'expose-namespaces' => [
        'Psr\\\\',
        'Composer\\\\Autoload\\\\',
    ],

    'exclude-namespaces' => [
        'Composer\\\\',
    ],

    'patchers' => [],
];
`;

    fs.writeFileSync(outputPath, phpContent, 'utf8');
}

function runScoper(outputDir) {


    const devDir = path.resolve(__dirname, '../', '../', 'dev');

    const cmd = `php vendor/bin/php-scoper add-prefix \
        --config=scoper.inc.php \
        --output-dir=` + outputDir;

    console.log('Running Scoper:\n' + cmd);

    console.log(`${devDir}`);

    execSync(cmd, {
        cwd: devDir,       // run INSIDE dev/
        stdio: 'inherit',
        shell: true
    });
}


/**
 * Copy a directory to another directory, replacing the target if it exists.
 *
 * @param {string} src  Absolute or relative source path
 * @param {string} dest Absolute or relative destination path
 */
function copyDirReplace(src, dest) {


    const source = path.resolve(src);
    const target = path.resolve(dest);

    // 1) Ensure source exists
    if (!fs.existsSync(source)) {
        throw new Error(`Source folder does not exist: ${source}`);
    }

    // 2) Remove destination folder if exists
    if (fs.existsSync(target)) {
        fs.rmSync(target, {recursive: true, force: true});
    }

    // 3) Ensure parent directory exists
    const parent = path.dirname(target);
    if (!fs.existsSync(parent)) {
        fs.mkdirSync(parent, {recursive: true});
    }

    // 4) Copy folder
    fs.cpSync(source, target, {recursive: true});

    console.log(`✔ Copied folder:\n${source}\n→\n${target}`);
}

module.exports = {copyDirReplace};

module.exports = {writeScoperFile};


module.exports = {getPackageSrcFolders};
