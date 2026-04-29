const fs = require('fs');
const path = require('path');
const {execSync} = require('child_process');

/**
 * Check if a port is free using shell commands (Unix/macOS).
 * @param {number} port
 * @returns {boolean}
 */
function isPortFree(port) {
    try {
        const result = execSync(`lsof -i :${port} -sTCP:LISTEN`, {stdio: 'pipe'}).toString();
        return result.trim() === '';
    } catch (e) {
        return true; // lsof fails if no process is using the port
    }
}

/**
 * Updates vite.json with a free port if the current one is in use.
 * @returns {Promise<{port: number, updated: boolean, config: object}>}
 */
async function updateServerConfigPort() {
    const configPath = path.resolve(__dirname, '../../config/vite.json');
    const serverConfig = require(configPath);

    // Step 1: Check if current port is free
    if (isPortFree(serverConfig.port)) {
        return {port: serverConfig.port, updated: false, config: serverConfig, isFree: true};
    }

    // Step 2: Find another free port from allowed list
    const freePort = serverConfig.allowedPorts.find(isPortFree);

    if (freePort && serverConfig.port !== freePort) {
        console.log(`🔧 Current port ${serverConfig.port} is in use. Switching to ${freePort}`);
        serverConfig.port = freePort;
        fs.writeFileSync(configPath, JSON.stringify(serverConfig, null, 2));
        return {port: freePort, updated: true, config: serverConfig, isFree: true};
    }

    console.warn('⚠️ No available ports found in allowedPorts.');
    return {port: serverConfig.port, updated: false, config: serverConfig, isFree: false};
}

module.exports = updateServerConfigPort;
