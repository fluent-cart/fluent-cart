const { glob } = require("glob");
const fs = require('fs');

// The directory where your Vue components are located
const componentsDir = [
    './resources/public/customer-profile/**/*.vue   ',
];  // Adjust this path to match your project structure

// The destination file where the new imports and component registration will go
const destinationFile = './resources/admin/mixin/useElementPlusComponents.js';

// Regex pattern to match Element Plus components
const elementPlusPattern = /<el-[a-z0-9-]*/g;

let componentSet = new Set();

// Find all Vue component files
glob(componentsDir).then(function (files) {
    files.forEach((file) => {
        const content = fs.readFileSync(file, 'utf-8');
        const matches = content.match(elementPlusPattern);



        if (matches) {
            matches.forEach((component) => {
                // Normalize component name (convert kebab-case to PascalCase)
                const componentName = component
                    .replace('<el-', '')  // Remove '<el-'
                    .split('-')           // Split by '-'
                    .map(part => part.charAt(0).toUpperCase() + part.slice(1)) // Capitalize each part
                    .join('');            // Join back into a single string

                // Add the component to the set (unique values)
                if (componentName) {
                    componentSet.add(`El${componentName}`);
                }
            });
        }
    });

    // Convert the Set to an Array
    const componentsArray = Array.from(componentSet).filter(Boolean); // Filter out any empty values

    // Read the existing file content
    const existingContent = fs.existsSync(destinationFile) ? fs.readFileSync(destinationFile, 'utf-8') : '';

    // Extract existing imports to avoid duplication
    const existingImportsPattern = /import\s*\{([^}]*)\}\s*from\s*'element-plus';/;
    const existingImportsMatch = existingContent.match(existingImportsPattern);
    let existingImports = existingImportsMatch ? existingImportsMatch[1].split(',').map(c => c.trim()) : [];

    // Merge new components with existing ones, removing duplicates
    const allComponents = Array.from(new Set([...existingImports, ...componentsArray])).filter(Boolean); // Filter out empty components

    // Generate new import statement
    const importStatements = `import { ${allComponents.join(', ')} } from 'element-plus';\n\n`;

    // Preserve non-component logic (like app.config.globalProperties)
    const globalPropertiesPattern = /app\.config\.globalProperties\.\$[\w]+\s*=\s*(El\w+(?:\.\w+)?);/g;
    const globalProperties = existingContent.match(globalPropertiesPattern) || [];

    // Generate new registration statements for components
    const registerStatements = `export function useElementPlusComponents(app) {\n` +
        allComponents.map(
            (comp) => `    app.component(${comp}.name, ${comp});`
        ).join('\n') +
        `\n\n` +
        globalProperties.join('\n') + // Append the existing global properties
        `\n}`;

    // Combine import and registration statements
    const finalFileContent = `${importStatements}${registerStatements}`;

    // Write the updated content to the destination file
    fs.writeFileSync(destinationFile, finalFileContent);
    console.log("File updated: useElementPlusComponents.js");
});
