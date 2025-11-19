<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
// Define an array of color palettes for random selection
$palettes = [
    ['bg' => '#FFF3E0', 'text' => '#D81B60', 'button' => '#F06292', 'button_text' => '#FFFFFF'],
    ['bg' => '#E3F2FD', 'text' => '#1976D2', 'button' => '#42A5F5', 'button_text' => '#FFFFFF'],
    ['bg' => '#E8F5E9', 'text' => '#2E7D32', 'button' => '#66BB6A', 'button_text' => '#FFFFFF'],
    ['bg' => '#F3E5F5', 'text' => '#6A1B9A', 'button' => '#AB47BC', 'button_text' => '#FFFFFF'],
    ['bg' => '#FFFDE7', 'text' => '#F57F17', 'button' => '#FFCA28', 'button_text' => '#000000']
];

// Select a random palette
$palette = $palettes[array_rand($palettes)];
?>

<table align="center" width="100%" border="0" cellpadding="0" cellspacing="0" role="presentation" style="text-align:center;margin-bottom:32px; background-color: <?php echo esc_attr($palette['bg']); ?>; padding: 20px; border-radius: 8px; border: 0px solid <?php echo esc_attr($palette['text']); ?>;">
    <tbody>
    <tr>
        <td>
            <h1 style="color: <?php echo esc_attr($palette['text']); ?>; font-size: 20px; margin: 0;">
                <?php echo esc_html($text); ?>
            </h1>
        </td>
    </tr>
    </tbody>
</table>
