const [major] = process.versions.node.split('.').map(Number);

if (major < 20) {
    console.error('\x1b[31m\x1b[1m❌🚨 Node.js version 20 or higher is required!! 🚨❌\x1b[0m');
    process.exit(1); // exit with error
}

const {glob} = require("glob")
const fs = require('fs');
const path = require('path');

const updateServerConfigPort = require('./portfinder.js');
const {spawn} = require("child_process");


const arguments = process.argv;

let mode = 'dev';
let switchTo = 'production';


if (typeof arguments[2] !== 'undefined' && arguments[2] === '--build') {
    mode = 'production';
    switchTo = 'dev';
}


const modeTitle = mode === 'dev' ? 'Development' : 'Production';
const regexObj = new RegExp(`["']env["']\\s+=>\\s*["']` + switchTo + `["'],?`, 'g');
const fakerRegex = new RegExp(`["']using_faker["']\\s+=>\\s*(true|false),?`, "g");
let fakerMode = true;

if (mode === 'production' && typeof process.env.npm_config_faker === 'undefined') {
    fakerMode = false;
}

(async () => {
    if (mode === 'dev') {

        const assetsPath = path.resolve('./assets');
        const buildPath = path.resolve('./builds');

        if (fs.existsSync(assetsPath)) {
            try {
                fs.rmSync(assetsPath, {recursive: true, force: true});
                console.log('🧹 Deleted assets folder.');
            } catch (err) {
            }
        }

        if (fs.existsSync(buildPath)) {
            try {
                fs.rmSync(buildPath, {recursive: true, force: true});
                console.log('🧹 Deleted builds folder.');
            } catch (err) {
            }
        }

        const configPath = path.resolve(__dirname, "../../config/vite_config.php");
        console.log(configPath,'configPath')
        if (fs.existsSync(configPath)) {
            fs.writeFileSync(configPath, '<?php return ' + '[]' + ';', "utf8");
            console.log("✅ Manifest array cleared");
        }

        const {port, updated, isFree} = await updateServerConfigPort();
        if (!isFree) {
            console.error(`❌ All allowed ports are currently in use. Exiting.`);
            process.exit(1);
        }

        if (updated) {
            console.log(`✅ Server port switched to ${port}`);
        } else {
            console.log(`✅ Server port ${port} is free. No update needed.`);
        }
    }

    if (mode === 'production') {

        let hadErrorOutput = false;

        const composer = spawn('composer', ['dump-autoload', '--classmap-authoritative'], {
            stdio: ['inherit', 'inherit', 'pipe'] // stdin: inherit, stdout: inherit, stderr: pipe
        });

        composer.stderr.on('data', (data) => {
            hadErrorOutput = true;
            //process.stderr.write(data); // still show the error
        });

        composer.on('close', (code) => {
            if (code === 0 && !hadErrorOutput) {
                console.log('✅ Composer autoload dump completed.');
            } else {
                console.error('\x1b[31m\x1b[1m❌🚨 There was some error while autoloading Composer! 🚨❌\x1b[0m');
            }
        });
    }


    // ...your glob/app.php logic
})();

const newFiles = glob(['config/app.php'])
newFiles.then(function (files) {
    files.forEach(function (item, index, array) {
        let data = fs.readFileSync(item, 'utf8');
        let result = data.replace(regexObj, "'env'            => '" + mode + "',");

        result = result.replace(fakerRegex, `'using_faker'    => ${fakerMode},`);

        fs.writeFile(item, result, 'utf8', function (err) {
            if (err) return console.log(err);
        });
        console.log(`✅ ${modeTitle} asset enqueued!`);
    });
})