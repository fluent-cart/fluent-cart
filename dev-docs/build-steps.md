# 🧩 Plugin Development Guide

This project provides a streamlined workflow for developing, building, and packaging a plugin, including support for
optional fake data.

## ⚙️ Requirements

- **Node.js**: v20.x or higher  
  Check your current version:
  ```bash
  node -v
  ```
- **npm**: comes with Node.js

## 🚧 Development

Start the plugin in development mode:

```bash
npm run dev
```

## 📦 Build

Create a production-ready build:

```bash
npm run build
```

## 🗜️ Build & Zip

Build the plugin and create a `.zip` archive:

```bash
npm run build -- --zip
```

The `.zip` will be placed in the `builds/` folder.

## 🤖 Build with Faker Support

Build the plugin including support of fake data:

```bash
npm run build -- --faker
```

## 🧪 Build, Zip, and Include Faker

Build with faker support and zip it:

```bash
npm run build -- --zip --faker
```

## 📁 Project Structure

```text
.
├── resources/       # Plugin source code
├── assets/          # Compiled output after build
├── builds/          # Zipped plugin packages
├── resources/dev/   # Build/zip utilities (if any)
└── README.md        # This file
```

## 🛠 Sample package.json Scripts

```json
{
  "scripts": {
    "dev": "vite",
    "build": "node scripts/build.js",
    "build:zip": "npm run build --zip",
    "build:faker": "npm run build --faker",
    "build:zip:faker": "npm run build --zip --faker"
  }
}
```

## ✅ Quick Reference

| Command                       | Description                   |
|-------------------------------|-------------------------------|
| `npm run dev`                 | Start development mode        |
| `npm run build`               | Production build              |
| `npm run build --zip`         | Build and zip output          |
| `npm run build --faker`       | Build with faker data         |
| `npm run build --zip --faker` | Build with faker + zip output |

Happy coding! 🚀
