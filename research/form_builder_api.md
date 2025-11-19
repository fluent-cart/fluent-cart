
---
title: Form Builder API
---

# Form Builder API

This document provides a comprehensive guide to building dynamic forms in FluentCart using a PHP schema. The forms are rendered by the `VueForm` component, which uses a set of renderer components located in `resources/admin/Bits/Components/Form/`.

## Introduction

FluentCart's form builder allows you to define complex forms using a declarative PHP schema. This schema is then passed to the `VueForm` component, which dynamically renders the form, including layouts, inputs, labels, tooltips, and validation errors. This approach separates the form structure from the presentation, making it easy to create and maintain complex forms.

## Basic Usage

To create a form, you need to define a PHP array that represents the form's schema. This schema is then passed to the `VueForm` component.

**Example PHP Schema:**

```php
$schema = [
    'name' => [
        'type'  => 'text',
        'label' => __('Name', 'fluent-cart'),
    ],
    'email' => [
        'type'  => 'email',
        'label' => __('Email', 'fluent-cart'),
    ],
];
```

## Layout Components

Layout components help you organize your form fields into a structured layout.

### `grid`

The `grid` component allows you to create a grid-based layout for your fields.

**Schema:**

```php
'my_grid' => [
    'type'    => 'grid',
    'columns' => 2, // Number of columns
    'schema'  => [
        'field1' => [
            'type'  => 'text',
            'label' => __('Field 1', 'fluent-cart'),
        ],
        'field2' => [
            'type'  => 'text',
            'label' => __('Field 2', 'fluent-cart'),
        ],
    ],
],
```

### `section`

The `section` component groups fields into a collapsible section.

**Schema:**

```php
'my_section' => [
    'type'        => 'section',
    'title'       => __('My Section', 'fluent-cart'),
    'description' => __('This is a section of fields.', 'fluent-cart'),
    'collapsible' => true, // or false
    'schema'      => [
        // ... fields ...
    ],
],
```

### `tab`

The `tab` component creates a tabbed interface for organizing fields.

**Schema:**

```php
'my_tabs' => [
    'type'   => 'tab',
    'schema' => [
        'tab1' => [
            'type'   => 'tab-pane',
            'title'  => __('Tab 1', 'fluent-cart'),
            'schema' => [
                // ... fields for tab 1 ...
            ],
        ],
        'tab2' => [
            'type'   => 'tab-pane',
            'title'  => __('Tab 2', 'fluent-cart'),
            'schema' => [
                // ... fields for tab 2 ...
            ],
        ],
    ],
],
```

## Input Components

Input components are the actual form fields that users interact with.

### `text`, `email`, `password`, `number`, `textarea`

These are standard input fields.

**Schema:**

```php
'my_text' => [
    'type'        => 'text',
    'label'       => __('Text Input', 'fluent-cart'),
    'placeholder' => __('Enter text', 'fluent-cart'),
],
'my_textarea' => [
    'type'        => 'textarea',
    'label'       => __('Textarea', 'fluent-cart'),
    'placeholder' => __('Enter a long text', 'fluent-cart'),
    'rows'        => 4,
],
```

### `switch`

Displays a toggle switch.

**Schema:**

```php
'enable_something' => [
    'type'  => 'switch',
    'label' => __('Enable Something', 'fluent-cart'),
],
```

### `checkbox`

Displays a single checkbox or a group of checkboxes.

**Schema (Single):**

```php
'agree_terms' => [
    'type'  => 'checkbox',
    'label' => __('I agree to the terms and conditions', 'fluent-cart'),
],
```

**Schema (Group):**

```php
'my_checkbox_group' => [
    'type'    => 'checkbox',
    'label'   => __('My Checkbox Group', 'fluent-cart'),
    'options' => [
        'option1' => __('Option 1', 'fluent-cart'),
        'option2' => __('Option 2', 'fluent-cart'),
    ],
],
```

### `radio`

Displays a group of radio buttons.

**Schema:**

```php
'my_radio_group' => [
    'type'    => 'radio',
    'label'   => __('My Radio Group', 'fluent-cart'),
    'options' => [
        'option1' => __('Option 1', 'fluent-cart'),
        'option2' => __('Option 2', 'fluent-cart'),
    ],
],
```

### `select`

Displays a dropdown select box.

**Schema:**

```php
'my_select' => [
    'type'    => 'select',
    'label'   => __('My Select', 'fluent-cart'),
    'options' => [
        ['value' => 'option1', 'label' => __('Option 1', 'fluent-cart')],
        ['value' => 'option2', 'label' => __('Option 2', 'fluent-cart')],
    ],
],
```

### `remote_select`

Displays a searchable select box that fetches options from a remote URL.

**Schema:**

```php
'my_remote_select' => [
    'type'        => 'remote_select',
    'label'       => __('My Remote Select', 'fluent-cart'),
    'placeholder' => __('Search for an option', 'fluent-cart'),
    'remote_url'  => admin_url('admin-ajax.php?action=my_remote_search_action'),
],
```

### `color`

Displays a color picker.

**Schema:**

```php
'brand_color' => [
    'type'  => 'color',
    'label' => __('Brand Color', 'fluent-cart'),
],
```

### `date`

Displays a date picker.

**Schema:**

```php
'start_date' => [
    'type'  => 'date',
    'label' => __('Start Date', 'fluent-cart'),
],
```

### `time`

Displays a time picker.

**Schema:**

```php
'start_time' => [
    'type'  => 'time',
    'label' => __('Start Time', 'fluent-cart'),
],
```

### `media`

Displays the WordPress media library uploader.

**Schema:**

```php
'my_image' => [
    'type'  => 'media',
    'label' => __('My Image', 'fluent-cart'),
],
```

### `wp-editor`

Displays the WordPress classic editor.

**Schema:**

```php
'my_editor' => [
    'type'       => 'wp-editor',
    'label'      => __('My Editor', 'fluent-cart'),
    'shortcodes' => [
        'data' => [/* ... shortcodes data ... */]
    ],
],
```

### `html`

Displays raw HTML content.

**Schema:**

```php
'my_html' => [
    'type'  => 'html',
    'value' => '<h3>My Custom HTML</h3>',
],
```

## Complete Schema Example

Here is a complete example of a PHP schema that demonstrates the usage of various layout and input components:

```php
$schema = [
    'my_tabs' => [
        'type'   => 'tab',
        'schema' => [
            'general_tab' => [
                'type'   => 'tab-pane',
                'title'  => __('General', 'fluent-cart'),
                'schema' => [
                    'name' => [
                        'type'  => 'text',
                        'label' => __('Name', 'fluent-cart'),
                    ],
                    'email' => [
                        'type'  => 'email',
                        'label' => __('Email', 'fluent-cart'),
                    ],
                ],
            ],
            'advanced_tab' => [
                'type'   => 'tab-pane',
                'title'  => __('Advanced', 'fluent-cart'),
                'schema' => [
                    'my_grid' => [
                        'type'    => 'grid',
                        'columns' => 2,
                        'schema'  => [
                            'brand_color' => [
                                'type'  => 'color',
                                'label' => __('Brand Color', 'fluent-cart'),
                            ],
                            'start_date' => [
                                'type'  => 'date',
                                'label' => __('Start Date', 'fluent-cart'),
                            ],
                        ],
                    ],
                    'my_section' => [
                        'type'        => 'section',
                        'title'       => __('My Section', 'fluent-cart'),
                        'collapsible' => true,
                        'schema'      => [
                            'enable_something' => [
                                'type'  => 'switch',
                                'label' => __('Enable Something', 'fluent-cart'),
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
];
```
