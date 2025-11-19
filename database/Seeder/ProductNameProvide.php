<?php

namespace FluentCart\Database\Seeder;

use FluentCart\Faker\Provider\Base;
use FluentCart\Framework\Support\Arr;

class ProductNameProvide extends Base
{
    public static function generateClothName(): string
    {
        $adjectives = array('Gorgeous', '', '', 'Elegant', '', '', 'Wonderful', '', '', 'Glittery', '', '', 'Dazzling');
        $colors = array('Green', 'Blue', 'Red', 'Violet', 'White', 'Black', 'Purple');
        $names = array('Jacket', 'Shorts', 'Coat', 'Jeans', 'Shirt', 'Suit', 'Polo shirt', 'Hoodie', 'Pant', 'Sweater', 'Dress');
        $adjectives = $adjectives[array_rand($adjectives)];
        $colors = $colors[array_rand($colors)];
        $names = $names[array_rand($names)];

        return $adjectives . ' ' . $colors . ' ' . $names;
    }

    public function generateTvName(): string
    {
        $brands = array('Samsung', 'Xiaomi', 'Lg', 'Sony', 'Toshiba', 'Panasonic', 'Philips',);
        $size = array('16 inch', '20 inch', '24inch', '32inch', '32inch', '49inch', '55inch',);

        $brands = $brands[array_rand($brands)];
        $size = $size[array_rand($size)];

        return $brands . ' ' . $size . ' Tv';
    }

    public static function generateMobileName(): string
    {
        $mobileBrands = ['Nokia', 'Samsung', 'Iphone', 'Oppo', 'Vivo', 'Xiaomi', 'Realme', 'OnePlus', 'Tecno'];

        $modelPrefix = ['N', '', '', 'V', '', '', 'Razor'];
        $modelSuffix = ['V', '', '', 'T', '', 'M'];

        $variants = ['128gb', '', '256gb', '', '512gb', '',];


        $name = $mobileBrands[array_rand($mobileBrands)];

        if ($name === 'Iphone') {
            $iphoneSuffix = ['Max', 'Pro', 'Pro Max', 'Max Pro'];
            $name .= ' ' . wp_rand(7, 15) . ' ' . $iphoneSuffix[array_rand($iphoneSuffix)];
        } else if ($name === 'Samsung') {
            $samsungPrefix = ['Z Flip', 'Z Fold', 'Note', 'Galaxy'];
            $samsungSuffix = ['Ultra', ''];
            $samsungNumberSuffix = ['A', 'S', 'M'];
            $name .= ' ' . $samsungPrefix[array_rand($samsungPrefix)] . ' ' . $samsungNumberSuffix[array_rand($samsungNumberSuffix)] . wp_rand(8, 30) . ' ' . $samsungSuffix[array_rand($samsungSuffix)];
        } else {

            $prefix = $modelPrefix[array_rand($modelPrefix)];

            $suffix = '';

            if (empty($prefix)) {
                $suffix = $modelSuffix[array_rand($modelSuffix)];
            }


            $name .= ' ' . $prefix . wp_rand(10, 50) . $suffix;
        }

        return trim($name . ' ' . $variants[array_rand($variants)]);

    }

    public static function generateFoodName(): string
    {
        $productNames = array(
            "Pizza",
            "Burger",
            "Spaghetti Carbonara",
            "Chicken Tikka Masala",
            "Sushi",
            "Ice Cream",
            "Chocolate Chip Cookies",
            "Caesar Salad",
            "Tacos",
            "Pancakes",
            "Steak",
            "Pad Thai",
            "Lasagna",
            "Chicken Alfredo",
            "Ramen",
            "Chicken Noodle Soup",
            "Fried Chicken",
            "Apple Pie",
            "Chocolate Brownies",
            "Cupcakes",
            "Macaroni and Cheese",
            "Fish and Chips",
            "Mango Lassi",
            "Fajitas",
            "Hamburger",
            "Pho",
            "Chicken Parmesan",
            "Cheesecake",
            "Mashed Potatoes",
            "Gelato",
            "Hot Dog",
            "Guacamole",
            "Churros",
            "Falafel",
            "Shrimp Scampi",
            "Pumpkin Pie",
            "Cannoli",
            "Hummus",
            "Croissant",
            "Clam Chowder",
            "Nachos",
            "Creme Brulee",
            "Beef Stroganoff",
            "Key Lime Pie",
            "Garlic Bread",
            "Baklava",
            "Chicken Satay",
            "Poutine",
            "Biscuits and Gravy",
            "Samosa",
            "Crème Caramel",
            "Fried Rice",
            "Shawarma",
            "Tom Yum",
            "Miso Soup",
            "Gyoza",
            "Spanakopita",
            "Spring Rolls",
            "Tiramisu",
            "Cuban Sandwich",
            "Pierogi",
            "Baklava",
            "Peking Duck",
            "Croque Madame",
            "Goulash",
            "Pani Puri",
            "Peking Duck",
            "Crab Rangoon",
            "Baba Ganoush",
            "Chimichanga",
            "Red Velvet Cake",
            "Ceviche",
            "Fish Tacos",
            "Gazpacho",
            "Pavlova",
            "Chicken Shawarma",
            "Lobster Bisque",
            "Borscht",
            "Jambalaya",
            "Ratatouille",
            "Tandoori Chicken",
            "Banana Bread",
            "Chicken Adobo",
            "Tempura",
            "Croque Monsieur",
            "Scones",
            "Kimchi",
            "Escargot",
            "Naan Bread",
            "Chicken Katsu",
            "Pierogi",
            "Chiles En Nogada",
            "Challah",
            "Pecan Pie",
            "Risotto",
            "Chicken Souvlaki",
            "Bakewell Tart",
            "Tarte Tatin",
            "Shrimp and Grits",
            "Cassoulet",
            "Welsh Rarebit",
            "Moussaka",
            "Bouillabaisse",
            "Lamb Tagine",
            "Blini",
            "Borscht",
            "Tzatziki",
            "Coleslaw",
            "Pav Bhaji",
            "Biryani",
            "Scotch Egg",
            "Vindaloo",
            "Tom Kha Gai",
        );

        return $productNames[array_rand($productNames)];
    }

    public static function generateElectronicProductName(): string
    {
        $productNames = [
            "iPhone 13",
            "Samsung Galaxy S21",
            "Sony PlayStation 5",
            "Dell XPS 13",
            "Apple MacBook Pro",
            "LG OLED TV",
            "Canon EOS R5",
            "Nintendo Switch",
            "Bose QuietComfort 35 II",
            "Amazon Echo Dot",
            "Microsoft Surface Laptop",
            "Fitbit Charge 4",
            "HP Spectre x360",
            "Sony WH-1000XM4",
            "GoPro HERO9 Black",
            "Samsung QLED TV",
            "Xbox Series X",
            "DJI Mavic Air 2",
            "Bose SoundLink Revolve",
            "Apple Watch Series 7",
            "Nikon Z6",
            "Sony A7 III",
            "LG NanoCell TV",
            "Google Pixel 6",
            "Microsoft Xbox Elite Controller",
            "Razer BlackWidow V3",
            "Amazon Kindle Oasis",
            "Sonos One",
            "NVIDIA GeForce RTX 3080",
            "Panasonic Lumix GH5",
            "Apple AirPods Pro",
            "Sony Xperia 1 III",
            "Lenovo ThinkPad X1 Carbon",
            "Xiaomi Mi 11",
            "Canon EOS Rebel T8i",
            "Logitech MX Master 3",
            "Fitbit Versa 3",
            "Huawei MateBook X Pro",
            "Garmin Forerunner 945",
            "Samsung Galaxy Tab S7",
            "JBL Flip 5",
            "ASUS ROG Strix G15",
            "Beats Studio Buds",
            "LG UltraGear Monitor",
            "Dell Alienware M15 R6",
            "Razer Blade 15",
            "Samsung Odyssey G9",
            "Apple iPad Pro",
            "Google Nest Hub",
            "OnePlus 9 Pro",
            "Corsair K70 RGB",
            "Microsoft Surface Pro 7",
            "Jabra Elite 85t",
            "Lenovo Legion 5",
            "Sony WF-1000XM4",
            "AMD Ryzen 9 5950X",
            "NVIDIA GeForce RTX 3070",
            "Bose Noise Cancelling Headphones 700",
            "Apple iMac",
            "ASUS ROG Zephyrus G14",
            "Fitbit Inspire 2",
            "Samsung Galaxy Buds Pro",
            "Logitech G Pro X",
            "Sony X800H 4K TV",
            "Acer Predator Helios 300",
            "Xiaomi Mi Electric Scooter",
            "Anker PowerCore 10000",
            "Raspberry Pi 4",
            "Roku Streaming Stick+",
            "Amazon Fire TV Stick",
            "Ring Video Doorbell Pro",
            "GoPro HERO10 Black",
            "NETGEAR Nighthawk AX12",
            "ASUS ZenWiFi AX",
            "Apple AirTag",
            "Sony WF-SP800N",
            "Samsung T7 Portable SSD",
            "Xbox Wireless Controller",
            "Canon PIXMA TR150",
            "Logitech G502 Hero",
            "Samsung Odyssey G7",
            "Bose SoundSport Free",
            "JBL Boombox 2",
            "Anker Soundcore Liberty Air 2 Pro",
            "Apple TV 4K",
            "Microsoft Surface Go 2",
            "Bose Frames",
            "Garmin Venu 2",
            "Samsung Galaxy Watch 4",
            "Sony HT-Z9F Soundbar",
            "LG CX OLED TV",
            "ASUS TUF Gaming VG27AQL1A",
            "Apple Magic Keyboard",
            "Sony X950H 4K TV",
            "Logitech C920s Webcam",
            "Amazon Echo Show 8",
            "Google Nest Thermostat",
        ];

        return $productNames[array_rand($productNames)];
    }

    public static function generateSubscribableProductName(): string
    {
        $productNames = [
            "YouTube Premium",
            "Netflix",
            "Spotify Premium",
            "Amazon Prime",
            "Disney+",
            "Apple One",
            "HBO Max",
            "Microsoft 365",
            "Adobe Creative Cloud",
            "Dropbox Plus",
            "FluentCRM Pro",
            "Fluent Forms Pro",
            "Fluent Support",
            "FluentSMTP",
            "Fluent Booking",
            "FluentAuth",
            "Shopify Plus",
            "WordPress VIP",
            "Google Workspace",
            "Notion Plus"
        ];
        return $productNames[array_rand($productNames)];
    }

    public static function generateSubscriptionName(): string
    {
        return static::generateSubscribableProductName();

    }


    public function getProduct($productTypes = null): array
    {
        $predefinedTypes = [
            'cloth'        => 1,
            'tv'           => 2,
            'mobile'       => 3,
            'food'         => 4,
            'subscription' => 5,
            'electronic'   => 6
        ];


        if ($productTypes !== null && is_string($productTypes)) {
            $productTypes = explode(",", $productTypes);
        } else {
            $productTypes = $predefinedTypes;
        }


        $arrayKeys = Arr::isAssoc($productTypes) ? array_keys($productTypes) : $productTypes;


        $generatableTypes = Arr::only($predefinedTypes, $arrayKeys);
        
        $randomNumber = $predefinedTypes[array_rand(($generatableTypes))];

        if ($randomNumber === 1) {
            return [
                'name'          => static::generateClothName(),
                'variationType' => 'cloth',
                'payment_type'  => 'onetime',
            ];
            //return static::generateClothName().'+'.'cloth';
        }
        if ($randomNumber === 2) {
            return [
                'name'          => static::generateTvName(),
                'variationType' => 'tv',
                'payment_type'  => 'onetime',
            ];
        }
        if ($randomNumber === 3) {
            return [
                'name'          => static::generateMobileName(),
                'variationType' => 'mobile',
                'payment_type'  => 'onetime',
            ];
        }
        if ($randomNumber === 4) {
            return [
                'name'          => static::generateFoodName(),
                'variationType' => 'food',
                'payment_type'  => 'onetime',
            ];
        }
        if ($randomNumber === 5) {
            return [
                'name'          => static::generateSubscriptionName(),
                'variationType' => 'subscribable',
                'payment_type'  => 'subscription',
            ];
        } else return [
            'name'          => static::generateElectronicProductName(),
            'variationType' => 'electronic',
            'payment_type'  => 'onetime',
        ];
    }

    public function productVariation($type, $productTitle, $paymentType, $index): string
    {
        if ($type === 'cloth') {
            return static::generateClothVariation();
        }
        if ($type === 'tv') {
            return static::generateTvVariation();
        }
        if ($type === 'mobile') {
            return static::generateMobileVariation();
        }
        if ($type === 'food') {
            return static::generateFoodVariation();
        }
        if ($type === 'subscribable') {
            return static::generateSubscribableVariation($productTitle, $paymentType, $index);
        } else return static::generateElectronicProductVariation();
    }

    public static function generateSubscribableVariation($productTitle, $paymentType, $index): string
    {

        $prefixes = ['Lifetime', 'Weekly', "Monthly", "Yearly"];

        return Arr::get($prefixes, $index, 'Lifetime') . " " . $productTitle;
//        if ($paymentType === 'onetime') {
//            return "Lifetime $productTitle";
//        } else {
//            $prefixes = ['Weekly', "Monthly", "Yearly"];
//
//            return $prefixes[array_rand($prefixes)] . " " . $productTitle;
//        }
    }

    public static function generateClothVariation(): string
    {
        $fabric = array('Cotton', 'Polyester', 'Silk', 'Wool', 'Linen', 'Velvet', 'Satin', 'Chiffon');
        $size = array('Small', 'Medium', 'Large', 'Extra-large', 'Plus sizes');
        $fabric = $fabric[array_rand($fabric)];
        $size = $size[array_rand($size)];

        return $fabric . ' ' . $size;
    }

    public function generateTvVariation(): string
    {
        $type = array("LED", "OLED", "QLED", "LCD", "Plasma", "Curved screens");
        $size = array("32 inches", "40 inches", "55 inches", "65 inches", "75 inches", "85 inches");
        $resolution = array("HD (720p)", "Full HD (1080p)", "4K Ultra HD (2160p)", "8K Ultra HD (4320p)");

        $type = $type[array_rand($type)];
        $size = $size[array_rand($size)];
        $resolution = $resolution[array_rand($resolution)];

        return $type . ' ' . $size . ' ' . $resolution;
    }

    public static function generateMobileVariation(): string
    {

        $size = ["Small (below 5 inches)", "Standard (5 to 6 inches)", "Large (above 6 inches)"];
        $storageCapacity = ["64GB", "128GB", "256GB", "512GB"];
        $cameraCapabilities = ["Single-camera", "Dual-camera", "Triple-camera", "Quad-camera", "Front camera resolution"];

        $size = $size[array_rand($size)];
        $storageCapacity = $storageCapacity[array_rand($storageCapacity)];
        $cameraCapabilities = $cameraCapabilities[array_rand($cameraCapabilities)];

        return $size . ' ' . $storageCapacity . ' ' . $cameraCapabilities;
    }

    public static function generateFoodVariation(): string
    {

        $cuisine = ["Italian", "Chinese", "Indian", "Mexican", "Japanese", "Thai", "American", "French", "Mediterranean", "Middle Eastern"];
        $category = ["Snacks", "Beverages", "Dairy products", "Meat and poultry", "Fruits and vegetables"];
        $preference = ["Vegan", "Vegetarian", "Gluten-free", "Organic", "Halal", "Kosher"];
        $flavor = ["Sweet", "Salty", "Spicy", "Sour", "Bitter", "Umami"];
        $packagingSize = ["Family packs", "Individual servings", "Economy size", "Travel-size packs"];

        $cuisine = $cuisine[array_rand($cuisine)];
        $category = $category[array_rand($category)];
        $preference = $preference[array_rand($preference)];
        $flavor = $flavor[array_rand($flavor)];
        $packagingSize = $packagingSize[array_rand($packagingSize)];

        return $cuisine . ' ' . $category . ' ' . $preference . ' ' . $flavor . ' ' . $packagingSize;
    }

    public static function generateElectronicProductVariation(): string
    {
        $displaySize = ["11-inch", "13-inch", "15-inch", "17-inch", "24-inch", "32-inch", "40-inch", "55-inch", "65-inch", "75-inch"];
        $screenResolution = ["HD (720p)", "Full HD (1080p)", "4K Ultra HD (2160p)", "8K Ultra HD (4320p)"];
        $memory = ["4GB", "8GB", "16GB", "32GB", "64GB", "128GB", "256GB", "512GB", "1TB", "2TB"];
        $cameraResolution = ["12MP", "16MP", "20MP", "24MP", "32MP", "48MP", "64MP", "108MP"];
        $batteryLife = ["8 hours", "10 hours", "12 hours", "24 hours"];

        $displaySize = $displaySize[array_rand($displaySize)];
        $screenResolution = $screenResolution[array_rand($screenResolution)];
        $memory = $memory[array_rand($memory)];
        $cameraResolution = $cameraResolution[array_rand($cameraResolution)];
        $batteryLife = $batteryLife[array_rand($batteryLife)];

        return $displaySize . ' ' . $screenResolution . ' ' . $memory . ' ' . $cameraResolution . ' Battery - Up to ' . $batteryLife;
    }

    public static function productSignUpFeeName(): string
    {
        $feeNames = array(
            'QuickStart',
            'SwiftSetup',
            'RapidCharge',
            'TurboInstall',
            'SnapSetup',
            'BlitzCharge',
            'FastTrack',
            'InstaCharge',
            'EasyStart',
            'SpeedySetup',
            'LightningCharge',
            'PromptInstall',
            'ExpressSetup',
            'FlashCharge',
            'SimpleStart'
        );

        return $feeNames[array_rand($feeNames)];
    }
}