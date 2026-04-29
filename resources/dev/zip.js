const path = require('path');
const { exec, spawn } = require('child_process');
const { promisify } = require('util');
const execAsync = promisify(exec);
function buildWithSpawn(includeFaker = false) {
    return new Promise((resolve, reject) => {
        const scriptPath = path.join(__dirname, 'build.sh');
        const args = includeFaker ? ['--faker'] : [];

        console.log(`🚀 Running: ${scriptPath} ${args.join(' ')}`);

        const child = spawn(scriptPath, args, {
            stdio: 'inherit', // This pipes stdout/stderr to parent process
            shell: false
        });

        child.on('close', (code) => {
            if (code === 0) {
                console.log('✅ Build completed successfully');
                resolve(true);
            } else {
                console.error(`❌ Build failed with exit code ${code}`);
                reject(new Error(`Build failed with exit code ${code}`));
            }
        });

        child.on('error', (error) => {
            console.error('❌ Build error:', error.message);
            reject(error);
        });
    });
}

if (typeof process.env.npm_config_faker === 'undefined') {
    buildWithSpawn(false);
}else{
    buildWithSpawn(true);
}