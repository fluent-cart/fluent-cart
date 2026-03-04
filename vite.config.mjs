import {defineConfig} from "vite";
import {viteStaticCopy} from "vite-plugin-static-copy";
import vue from "@vitejs/plugin-vue";
import react from "@vitejs/plugin-react";
import path from "path";
//import AutoImport from "unplugin-auto-import/vite";
import fs from "fs"; // Add this dependency for file operations

const serverConfig = require('./config/vite.json');

// Read plugin version from fluent-cart.php
function getPluginVersion() {
    const pluginFile = fs.readFileSync(path.resolve(__dirname, 'fluent-cart.php'), 'utf8');
    const match = pluginFile.match(/define\s*\(\s*['"]FLUENTCART_VERSION['"]\s*,\s*['"]([^'"]+)['"]\s*\)/);
    return match ? match[1] : '1.0.0';
}

const PLUGIN_VERSION = getPluginVersion();
console.log(`📦 Building FluentCart v${PLUGIN_VERSION}`);

// Plugin to add cache busting query string to all chunk imports
const chunkCacheBustPlugin = {
    name: 'chunk-cache-bust',
    renderDynamicImport() {
        return {
            left: 'import(',
            right: ' + "?ver=' + PLUGIN_VERSION + '")'
        };
    },
    renderChunk(code) {
        // Add version to static imports between chunks (from "./file.js" and import "./file.js")
        const ver = '?ver=' + PLUGIN_VERSION;
        return code.replace(/(from\s*["'])(\.\/[^"']+\.js)(["'])/g, `$1$2${ver}$3`)
                   .replace(/(import\s*["'])(\.\/[^"']+\.js)(["'])/g, `$1$2${ver}$3`);
    }
};

// https://vitejs.dev/config/

//Add All CSS and js here
const inputs = [
    "resources/admin/bootstrap/app.js",
    "resources/styles/tailwind/style.css",
    // Block Editor assets
    "resources/admin/BlockEditor/ReactSupport.js",
    "resources/admin/BlockEditor/ShopApp/ShopAppBlockEditor.jsx",
    "resources/admin/BlockEditor/ShopApp/InnerBlocks/InnerBlocks.jsx",
    "resources/admin/BlockEditor/ShopApp/style/shop-app-block-editor.css",
    "resources/admin/BlockEditor/PricingTable/PricingTableBlockEditor.jsx",
    "resources/admin/BlockEditor/PricingTable/style/pricing-table-block-editor.scss",
    "resources/admin/BlockEditor/SearchBar/SearchBarBlockEditor.jsx",
    "resources/admin/BlockEditor/CustomerProfile/CustomerProfileBlockEditor.jsx",
    "resources/admin/BlockEditor/CustomerProfile/style/customer-profile-block-editor.scss",
    "resources/admin/BlockEditor/Checkout/CheckoutBlockEditor.jsx",
    "resources/admin/BlockEditor/Checkout/InnerBlocks/InnerBlocks.jsx",
    "resources/admin/BlockEditor/Checkout/style/checkout-block-editor.scss",

    "resources/admin/BlockEditor/ProductCard/ProductCardBlockEditor.jsx",
    "resources/admin/BlockEditor/ProductCard/style/product-card-block-editor.scss",
    "resources/public/buttons/direct-checkout/style/style.scss",

    "resources/admin/BlockEditor/ProductCarousel/ProductCarouselBlockEditor.jsx",
    "resources/admin/BlockEditor/ProductCarousel/InnerBlocks/InnerBlocks.jsx",
    "resources/admin/BlockEditor/ProductCarousel/style/product-carousel-block-editor.scss",
    "resources/public/carousel/products/style/product-carousel.scss",
    "resources/public/carousel/products/product-carousel.js",

    "resources/admin/BlockEditor/MediaCarousel/MediaCarouselBlockEditor.jsx",
    "resources/admin/BlockEditor/MediaCarousel/InnerBlocks/InnerBlocks.jsx",
    "resources/admin/BlockEditor/MediaCarousel/style/media-carousel-block-editor.scss",
    "resources/public/carousel/media/style/media-carousel.scss",
    "resources/public/carousel/media/media-carousel.js",

    "resources/public/buttons/add-to-cart/style/style.scss",
    "resources/public/buttons/product-details/style/style.scss",

    "resources/public/search-bar-app/style/style.scss",

    'resources/admin/BlockEditor/BuySection/BuySectionBlockEditor.jsx',
    'resources/admin/BlockEditor/BuySection/style/buy-section-block-editor.scss',
    'resources/admin/BlockEditor/Excerpt/ExcerptBlockEditor.jsx',
    'resources/admin/BlockEditor/Excerpt/style/excerpt-block-editor.scss',
    'resources/admin/BlockEditor/ProductDescription/ProductDescriptionBlockEditor.jsx',
    'resources/admin/BlockEditor/ProductDescription/style/product-description-block-editor.scss',
    'resources/admin/BlockEditor/PriceRange/PriceRangeBlockEditor.jsx',
    'resources/admin/BlockEditor/PriceRange/style/price-range-block-editor.scss',
    // Sale Badge
    'resources/admin/BlockEditor/SaleBadge/SaleBadgeBlockEditor.jsx',
    'resources/admin/BlockEditor/SaleBadge/style/sale-badge-block-editor.scss',
    // Sold Out Badge
    'resources/admin/BlockEditor/SoldOutBadge/SoldOutBadgeBlockEditor.jsx',
    'resources/admin/BlockEditor/SoldOutBadge/style/sold-out-badge-block-editor.scss',
    'resources/admin/BlockEditor/ProductInfo/ProductInfoBlockEditor.jsx',
    'resources/admin/BlockEditor/ProductInfo/style/product-info-block-editor.scss',
    'resources/admin/BlockEditor/ProductGallery/ProductGalleryBlockEditor.jsx',
    'resources/admin/BlockEditor/ProductGallery/style/product-gallery-block-editor.scss',
    'resources/admin/BlockEditor/Stock/StockBlock.jsx',

    // Buttons
    'resources/admin/BlockEditor/Buttons/AddToCartButtonBlockEditor.jsx',
    'resources/admin/BlockEditor/Buttons/BuyNowButtonBlockEditor.jsx',
    'resources/admin/BlockEditor/Buttons/style/button-block-editor.scss',

    'resources/admin/BlockEditor/Components/style/fct-global-block-editor.scss',

    // Product Categories List
    'resources/admin/BlockEditor/ProductCategoriesList/ProductCategoriesListBlockEditor.jsx',
    'resources/admin/BlockEditor/ProductCategoriesList/style/product-categories-list-block-editor.scss',
    'resources/public/product-categories-list/product-categories-list.scss',
    'resources/public/product-categories-list/product-categories-list.js',

    // Customer Dashboard Button
    'resources/admin/BlockEditor/CustomerDashboardButton/CustomerDashboardButtonBlockEditor.jsx',
    'resources/admin/BlockEditor/CustomerDashboardButton/style/customer-dashboard-button-block-editor.scss',
    'resources/public/customer-dashboard-button/customer-dashboard-button.scss',

    // Store Logo
    'resources/admin/BlockEditor/StoreLogo/StoreLogoBlockEditor.jsx',

    // Related products
    'resources/admin/BlockEditor/RelatedProduct/RelatedProductBlockEditor.jsx',
    'resources/admin/BlockEditor/RelatedProduct/style/related-product-block-editor.scss',

    // product card
    //"resources/public/product-card/product-card.js",
    "resources/public/product-card/style/product-card.scss",
    'resources/public/product-card/product-card.js',
    // Shop App/Product Page
    "resources/public/product-page/ShopApp.js",
    "resources/public/product-page/style/shop-app.scss",
    // Order-Receipt
    "resources/public/print/Print.js",
    // SearchBar App
    "resources/public/search-bar-app/SearchBarApp.js",
    // Cart Js
    //"resources/public/cart/Cart.js",

    // Cart Drawer
    //"resources/public/cart-drawer/CartDrawer.js",
    "resources/public/cart-drawer/cart-drawer.scss",
    "resources/public/cart-drawer/mini-cart.scss",
    "resources/admin/BlockEditor/Cart/style/mini-cart-block-editor.scss",
    "resources/admin/BlockEditor/Cart/MiniCartBlockEditor.jsx",
    "resources/admin/BlockEditor/ProductTitle/ProductTitleBlockEditor.jsx",
    "resources/admin/BlockEditor/ProductTitle/style/product-title-block-editor.scss",
    "resources/admin/BlockEditor/ProductImage/ProductImageBlockEditor.jsx",
    "resources/admin/BlockEditor/ProductImage/style/product-image-block-editor.scss",

    // Product SKU
    'resources/admin/BlockEditor/ProductSku/ProductSkuBlockEditor.jsx',
    'resources/admin/BlockEditor/ProductSku/style/product-sku-block-editor.scss',

    "resources/admin/global.js",
    "resources/admin/admin_hooks.js",
    "resources/admin/utils/edit-wp-user-global.js",

    // Globals
    "resources/public/globals/FluentCartApp.js",
    "resources/public/globals/style.scss",

    // Checkout
    "resources/public/checkout/FluentCartCheckout.js",
    "resources/public/checkout/style/checkout.scss",
    "resources/public/checkout/style/confirmation.scss",
    "resources/public/checkout/registration.js",
    "resources/public/checkout/login.js",
    "resources/public/customer-profile/Start.js",
    "resources/public/customer-profile/style/customer-profile-global.scss",
    "resources/public/customer-profile/style/customer-profile.scss",
    "resources/public/checkout/style/registration.scss",
    "resources/public/checkout/style/login.scss",
    // Gutenberg
    "resources/public/gutenberg/gutenberg.js",
    // CustomPayment
    "resources/public/payments/custom-payment-page.js",
    "resources/public/payments/custom-payment-page.scss",
    // Single Product Page
    "resources/public/single-product/SingleProduct.js",
    "resources/public/single-product/single-product.scss",
    'resources/public/single-product/xzoom/xzoom.js',
    'resources/public/single-product/xzoom/xzoom.css',
    // Similar product
    "resources/public/single-product/similar-product.scss",
    "resources/public/payment-methods/paypal-checkout.js",
    "resources/public/payment-methods/stripe-checkout.js",
    "resources/public/payment-methods/stripe-hosted-checkout.js",
    "resources/public/payment-methods/cod-checkout.js",
    "resources/public/receipt/style/thank_you.scss",
    // Order Bump
    "resources/order-bump/order-bump.js",
    // Licensing
    "resources/licensing/license.js",
    // Subscriptions
    "resources/admin/Modules/Subscriptions/subscription.js",
    "resources/admin/Modules/Shipping/shipping.js",
    // Pricing Table
    "resources/public/pricing-table/PricingTable.js",
    "resources/public/pricing-table/pricing-table.scss",
    // Pricing Table Tab Js
    "resources/public/pricing-table/tab/Tab.js",

    //Elementor
    "resources/admin/elementor/ShopAppWidget.js",

    "resources/admin/elementor/DirectCheckout/Start.js",
    "resources/admin/elementor/AddToCart/Start.js",

    "resources/admin/elementor/PricingTable/Start.js",

    "resources/admin/elementor/ProductSearchBar/Start.js",
    "resources/admin/elementor/ProductDetailsButton/Start.js",
    "resources/admin/elementor/CustomerProfile/Start.js",

    "resources/public/components/select/style/style.scss",

    "resources/public/orderbump/orderbump.js",
    "resources/styles/tailwind/taxonomy.scss",
    "resources/public/checkout/style/modal-checkout.scss",
    "resources/public/checkout/style/checkout-iframe.scss",
    "resources/public/checkout/ModalCheckoutForm.js",
];

let viteConfig;

const moveManifestPlugin = {
    name: "move-manifest",
    configResolved(resolvedConfig) {
        viteConfig = resolvedConfig;
    },
    writeBundle() {
        const outDir = viteConfig.build.outDir;
        const manifestSrc = path.join(outDir, ".vite", "manifest.json");
        const manifestDest = path.resolve(__dirname, serverConfig.manifest_path);
        const viteDir = path.join(outDir, ".vite");

        if (fs.existsSync(manifestSrc)) {
            // Move the manifest file
            fs.renameSync(manifestSrc, manifestDest);

            // Remove empty .vite directory if exists
            if (fs.existsSync(viteDir) && fs.readdirSync(viteDir).length === 0) {
                fs.rmSync(viteDir, {recursive: true});
            }

            // Read manifest.json content
            const manifestContent = JSON.parse(fs.readFileSync(manifestDest, "utf8"));

            // Convert JSON to PHP array string
            const phpArray = jsonToPhpArray(manifestContent);

            // Update config/app.php
            const configPath = path.resolve(__dirname, "config/vite_config.php");
            //create if not exists
            if (!fs.existsSync(configPath)) {
                fs.writeFileSync(configPath, '<?php return [];', "utf8");
            }
            let configData = fs.readFileSync(configPath, "utf8");

            // Replace or insert 'manifest' key

            fs.writeFileSync(configPath, '<?php return ' + phpArray + ';', "utf8");
            console.log("✅ Manifest array injected into config/vite_config.php");
        }
    },
};

// Helper function to convert JSON to PHP array syntax
function jsonToPhpArray(obj, indentLevel = 1) {
    const indent = "    ".repeat(indentLevel);
    if (Array.isArray(obj)) {
        return "[\n" +
            obj.map(v => `${indent}${valueToPhp(v, indentLevel + 1)}`).join(",\n") +
            "\n" + "    ".repeat(indentLevel - 1) + "]";
    } else if (typeof obj === "object" && obj !== null) {
        return "[\n" +
            Object.entries(obj)
                .map(([key, value]) => `${indent}'${key}' => ${valueToPhp(value, indentLevel + 1)}`)
                .join(",\n") +
            "\n" + "    ".repeat(indentLevel - 1) + "]";
    }
    return valueToPhp(obj, indentLevel);
}

function valueToPhp(value, indentLevel) {
    if (typeof value === "string") {
        return `'${value.replace(/'/g, "\\'")}'`;
    } else if (typeof value === "number") {
        return String(value);
    } else if (typeof value === "boolean") {
        return value ? "true" : "false";
    } else if (value === null) {
        return "null";
    } else if (Array.isArray(value) || typeof value === "object") {
        return jsonToPhpArray(value, indentLevel);
    }
    return "null";
}


export default defineConfig({
    base: '',
    css: {
        preprocessorOptions: {
            scss: {
                api: "modern",
            }
        }
    },
    plugins: [

        vue(),
        react({
            fastRefresh: false
        }),
        //liveReload([`${__dirname}/**/*\.php`]),
        viteStaticCopy({
            targets: [
                {src: "resources/images", dest: ""},
                {src: "resources/public/lib", dest: "public/"},
                {src: "resources/world.geo.json", dest: ""},
            ],
        }),
        // AutoImport({
        //     resolvers: [],
        // }),
        moveManifestPlugin
    ],

    build: {
        sourcemap: false,
        manifest: true,
        outDir: "assets",
        //assetsDir: '',
        publicDir: "assets",
        //root: '/',
        emptyOutDir: true, // delete the contents of the output directory before each build

        // https://rollupjs.org/guide/en/#big-list-of-options
        rollupOptions: {
            input: inputs,
            output: {
                manualChunks: undefined,
                chunkFileNames: "chunks/[name].js",
                entryFileNames: "[name].js",
                assetFileNames: "assets/[name][extname]",
            },
            plugins: [chunkCacheBustPlugin],
        },
    },

    resolve: {
        alias: {
            vue: "vue/dist/vue.esm-bundler.js",
            "@": path.resolve(__dirname, "resources/admin"),
        },
    },

    server: {
        port: serverConfig.port,
        strictPort: serverConfig.strict_port,
        hmr: {
            port: serverConfig.port,
            host: serverConfig.host,
            protocol: serverConfig.vite_protocol,
        },
        cors: {
            origin: "*",
            methods: ["GET"],
            allowedHeaders: ["Content-Type", "Authorization"],
        },
    },
    esbuild: {
        loader: "jsx",
    },
    optimizeDeps: {
        include: ["vue", "vue-draggable-next", "element-plus"]
    },
});
