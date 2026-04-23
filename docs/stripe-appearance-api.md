# Stripe Appearance API Integration

FluentCart now supports the [Stripe Appearance API](https://docs.stripe.com/elements/appearance-api), allowing you to customize the look and feel of Stripe Elements to match your website's design.

## Usage

You can use the `fluent_cart_stripe_appearance` filter to customize the appearance of Stripe Elements:

```php
function stripe_appearance($appearance) {
    return array(
        'theme'  => 'night',
        'labels' => 'floating',
        'variables' => array(
            'colorPrimary' => '#0570de',
            'colorBackground' => '#ffffff',
            'colorText' => '#30313d',
            'colorDanger' => '#df1b41',
            'fontFamily' => 'Ideal Sans, system-ui, sans-serif',
            'spacingUnit' => '2px',
            'borderRadius' => '4px',
        ),
        'rules' => array(
            '.Block' => array(
                'border' => '1px solid #E0E6EB',
                'boxShadow' => '0px 1px 1px rgba(0, 0, 0, 0.03), 0px 3px 6px rgba(18, 42, 66, 0.02)',
            ),
        )
    );
}

add_filter('fluent_cart/stripe_appearance', 'stripe_appearance', 10, 1);
```

## Available Options

### Themes
You can choose from the following pre-built themes:
- `stripe` (default)
- `night`
- `flat`

### Variables
Common variables you can customize:
- `colorPrimary` - A primary color used throughout the Element
- `colorBackground` - The color used for the background of inputs, tabs, and other components
- `colorText` - The default text color used in the Element
- `colorDanger` - A color used to indicate errors or destructive actions
- `fontFamily` - The font family used throughout Elements
- `fontSizeBase` - The font size that's set on the root of the Element
- `spacingUnit` - The base spacing unit that all other spacing is derived from
- `borderRadius` - The border radius used for tabs, inputs, and other components

### Labels
Control the position and visibility of labels associated with input fields:
- `auto` - Labels adjust based on the input variant (default)
- `above` - Labels are positioned above the corresponding input fields
- `floating` - Labels float within the input fields

### Inputs
Choose the style of input fields:
- `spaced` - Each input field has space surrounding it (default)
- `condensed` - Related input fields are grouped together without space between them

### Rules
For more granular control, you can use CSS-like rules to style individual components. See the [Stripe documentation](https://docs.stripe.com/elements/appearance-api) for all available selectors and properties.

## Example: Matching Your Brand

Here's an example of how to customize Stripe Elements to match your brand:

```php
function my_brand_stripe_appearance($appearance) {
    // Get your brand colors from theme customizer or options
    $primary_color = get_theme_mod('primary_color', '#3366cc');
    $background_color = get_theme_mod('background_color', '#ffffff');
    $text_color = get_theme_mod('text_color', '#333333');
    
    return array(
        'theme' => 'stripe',
        'variables' => array(
            'colorPrimary' => $primary_color,
            'colorBackground' => $background_color,
            'colorText' => $text_color,
            'fontFamily' => '"Your Brand Font", system-ui, sans-serif',
            'borderRadius' => '8px',
            'spacingUnit' => '4px',
        ),
    );
}

add_filter('fluent_cart/stripe_appearance', 'my_brand_stripe_appearance', 10, 1);
```

This code allows you to dynamically pull your brand colors from the WordPress customizer and apply them to Stripe Elements.
