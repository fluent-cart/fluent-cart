<?php if ( ! defined( 'ABSPATH' ) ) exit;

return array_merge(
    require __DIR__ . '/rates/europe.php',
    require __DIR__ . '/rates/americas.php',
    require __DIR__ . '/rates/asia.php',
    require __DIR__ . '/rates/africa.php',
    require __DIR__ . '/rates/oceania.php'
);
