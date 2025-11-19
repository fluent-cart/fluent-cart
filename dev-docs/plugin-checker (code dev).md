# WordPress Plugin Checker Configuration

This document provides guidance on configuring the [WordPress Plugin Checker](https://github.com/WordPress/plugin-check).  
It includes examples for customizing editor links for quick file navigation, as well as ignoring specific directories and files during validation.

---

## 📌 Description

The WordPress Plugin Checker is a tool to help developers ensure that plugins meet WordPress coding standards and guidelines.  
This configuration allows you to:

1. Open files directly in your preferred code editor when validation errors occur.
2. Exclude directories that are not relevant to plugin validation.
3. Ignore specific files that should not be checked.

---

## 💻 Editor Links

You can define multiple editor URL templates so errors open directly in your preferred IDE or code editor.

```php
// Open files in PhpStorm
add_filter( 'wp_plugin_check_validation_error_source_file_editor_url_template', function () {
    return 'phpstorm://open?file={{file}}&line={{line}}';
} );

// Open files in Visual Studio Code
add_filter( 'wp_plugin_check_validation_error_source_file_editor_url_template', function () {
    return 'vscode://file/{{file}}:{{line}}';
} );

// Open files in Sublime Text
add_filter( 'wp_plugin_check_validation_error_source_file_editor_url_template', function () {
    return 'subl://open?url=file://{{file}}&line={{line}}';
} );

// Open files in Atom
add_filter( 'wp_plugin_check_validation_error_source_file_editor_url_template', function () {
    return 'atom://core/open/file?filename={{file}}&line={{line}}';
} );

```

# Ignoring Files and Directories in WordPress Plugin Checker

This guide explains how to configure the **WordPress Plugin Checker** to ignore specific files and directories during plugin validation.  
Ignoring certain files or folders helps reduce false positives, speeds up checks, and keeps your workflow clean.

---

## 📌 Why Ignore Files and Directories?

During plugin validation, some files or folders may not be relevant to the plugin's functionality or coding standards:

- **Development files** (e.g., `dev`, `.idea`)  
- **Libraries or third-party code** (e.g., `app/Services/Libs`)  
- **Generated files** (e.g., translation files, zip files, temporary files)  

By ignoring these, you focus only on the important plugin code.

---

## 📁 Ignoring Directories

To ignore directories, you can use the `wp_plugin_check_ignore_directories` filter.
vendor .git and node_modules folders are ignored by default.
```php
add_filter( 'wp_plugin_check_ignore_directories', function ($dirs) {
    // Development directories
    $dirs = array_merge($dirs, [
        'dev',
        'dev-docs',
        '.idea',
        // Libraries
        'app/Services/Libs/Emogrifier',
        'app/Services/Libs/Spout',
    ]);    
    return $dirs;
} );
```



## 📁 Ignoring Directories

To ignore files, you can use the `wp_plugin_check_ignore_files` filter.

```php
add_filter( 'wp_plugin_check_ignore_files', function ($files) {
    // Translation or language files
   $files = array_merge($files, [
        'admin-translation.php',
        // Git and distribution files
        '.gitignore',
        '.gitattributes',
        '.distignore',
        '.zipignore',
        // Other files to ignore
        'README.md',
        'CHANGELOG.md',
        'Clipboard.js',
    ]);

    
    return $files;
} );
```

