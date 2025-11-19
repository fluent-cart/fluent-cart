<?php

namespace FluentCart\App\Modules\Templating\BlockTemplates\TemplateParts;

class ProductModalTemplatePart
{
    /**
     * The slug of the template part.
     *
     * @var string
     */
    const SLUG = 'product-modal';

    /**
     * The area of the template part.
     *
     * @var string
     */
    const AREA = 'uncategorized';

    /**
     * Plugin namespace for template parts.
     *
     * @var string
     */
    const PLUGIN_NAMESPACE = 'fluent-cart';

    /**
     * Initialization method.
     */
    public function init()
    {
        add_action('init', [$this, 'register']);
    }

    /**
     * Register the template part.
     */
    public function register()
    {



        add_filter('get_block_templates', [$this, 'addTemplatePart'], 10, 3);
        add_filter('pre_get_block_file_template', [$this, 'getTemplatePart'], 10, 3);
    }

    /**
     * Add the template part to the list of available template parts.
     *
     * @param array $query_result Array of found block templates.
     * @param array $query Arguments to retrieve templates.
     * @param string $template_type wp_template or wp_template_part.
     * @return array Modified array of block templates.
     */
    public function addTemplatePart($query_result, $query, $template_type)
    {
        if ('wp_template_part' !== $template_type) {
            return $query_result;
        }

       // dd($query_result);

        // Check if our template part is already in the results
        $template_id = $this->getTemplatePartId();
        foreach ($query_result as $template) {
            if ($template->id === $template_id) {
                return $query_result;
            }
        }

        // Create the template part object
        $template_part = $this->buildTemplatePartObject();

        $query_result[] = $template_part;

        return $query_result;
    }

    /**
     * Get template part when requested.
     *
     * @param \WP_Block_Template|null $template Block template object.
     * @param string $id Template unique identifier.
     * @param string $template_type Template type.
     * @return \WP_Block_Template|null
     */
    public function getTemplatePart($template, $id, $template_type)
    {
        if ('wp_template_part' !== $template_type) {
            return $template;
        }

        if ($this->getTemplatePartId() !== $id) {
            return $template;
        }

        return $this->buildTemplatePartObject();
    }

    /**
     * Build the template part object.
     *
     * @return \WP_Block_Template
     */
    private function buildTemplatePartObject()
    {
        $template_part = new \WP_Block_Template();
        $template_part->type = 'wp_template_part';
        $template_part->theme = get_stylesheet(); // Use current theme
        $template_part->slug = self::SLUG;
        $template_part->id = $this->getTemplatePartId();
        $template_part->title = $this->getTitle();
        $template_part->description = $this->getDescription();
        $template_part->content = $this->getDefaultTemplate();
        $template_part->source = 'plugin';
        $template_part->origin = 'plugin';
        $template_part->status = 'publish';
        $template_part->has_theme_file = false;
        $template_part->is_custom = true;
        $template_part->area = self::AREA;
        $template_part->post_types = [];
        $template_part->plugin = self::PLUGIN_NAMESPACE;

        return $template_part;
    }

    /**
     * Returns the title of the template part.
     *
     * @return string
     */
    public function getTitle()
    {
        return _x('Product Modal', 'Template part name', 'fluent-cart');
    }

    /**
     * Returns the description of the template part.
     *
     * @return string
     */
    public function getDescription()
    {
        return __('Editable template part for Single Product Modal.', 'fluent-cart');
    }

    /**
     * Returns the default template content.
     *
     * @return string
     */
    public function getDefaultTemplate()
    {
        ob_start();
        ?>
        <!-- wp:group {"templateLock":"contentOnly","tagName":"main","className":"alignfull is-style-default","layout":{"type":"default"}} -->
        <main class="wp-block-group alignfull is-style-default">
            <!-- wp:group {"lock":{"move":true,"remove":true},"align":"wide","layout":{"type":"default"}} -->
            <div class="wp-block-group alignwide">
                <!-- wp:shortcode {"lock":{"move":true,"remove":true}} -->
                [fluent_cart_product_header]
                <!-- /wp:shortcode -->
            </div>
            <!-- /wp:group -->

            <!-- wp:paragraph {"placeholder":"Add your content here..."} -->
            <p></p>
            <!-- /wp:paragraph -->
        </main>
        <!-- /wp:group -->
        <?php
        return ob_get_clean();
    }

    /**
     * Get the current content of the template part (including user modifications).
     *
     * @return string|null
     */
    public function getTemplateContent()
    {
        // First, try to get the user-customized version from the database
        $template_part_post = $this->getTemplatePartPost();

        if ($template_part_post && !empty($template_part_post->post_content)) {
            return $template_part_post->post_content;
        }

        // Fall back to default template
        return $this->getDefaultTemplate();
    }

    /**
     * Get the template part post from the database.
     *
     * @return \WP_Post|null
     */
    private function getTemplatePartPost()
    {
        $args = [
            'post_type'              => 'wp_template_part',
            'post_status'            => ['publish', 'auto-draft'],
            'title'                  => $this->getTitle(),
            'posts_per_page'         => 1,
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        ];

        // Try to find by slug first
        $args['name'] = self::SLUG;
        $query = new \WP_Query($args);

        if ($query->have_posts()) {
            return $query->posts[0];
        }

        // Also check with theme-specific slug
        $args['name'] = get_stylesheet() . '//' . self::SLUG;
        $query = new \WP_Query($args);

        if ($query->have_posts()) {
            return $query->posts[0];
        }

        return null;
    }

    /**
     * Render the template part with current content (including user customizations).
     *
     * @param array $args Optional arguments to pass to the template part.
     * @return string Rendered HTML output.
     */
    public function render($args = [])
    {
        $content = $this->getTemplateContent();

        if (empty($content)) {
            return '';
        }

        // Apply filters before rendering (allows other plugins to modify)
        $content = apply_filters('fluent_cart_template_part_content', $content, self::SLUG, $args);
        $content = apply_filters('fluent_cart_template_part_content_' . self::SLUG, $content, $args);

        // Parse and render blocks
        $output = do_blocks($content);

        // Apply filters after rendering
        $output = apply_filters('fluent_cart_template_part_output', $output, self::SLUG, $args);
        $output = apply_filters('fluent_cart_template_part_output_' . self::SLUG, $output, $args);

        return $output;
    }

    /**
     * Render the template part and echo it.
     *
     * @param array $args Optional arguments to pass to the template part.
     */
    public function display($args = [])
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in render method
        echo $this->render($args);
    }

    /**
     * Get the template part ID.
     *
     * @return string
     */
    public function getTemplatePartId()
    {
        return get_stylesheet() . '//' . self::SLUG;
    }

    /**
     * Check if the template part has been customized by the user.
     *
     * @return bool
     */
    public function isCustomized()
    {
        $template_part_post = $this->getTemplatePartPost();
        return !is_null($template_part_post);
    }

    /**
     * Reset the template part to default (remove customizations).
     *
     * @return bool True on success, false on failure.
     */
    public function resetToDefault()
    {
        $template_part_post = $this->getTemplatePartPost();

        if ($template_part_post) {
            $result = wp_delete_post($template_part_post->ID, true);

            if ($result) {
                // Clear any caches
                wp_cache_delete('get_block_templates', 'block_templates');
                return true;
            }

            return false;
        }

        return true; // Already at default
    }

    /**
     * Export the template part content (for backup or migration).
     *
     * @return array
     */
    public function export()
    {
        return [
            'slug'        => self::SLUG,
            'title'       => $this->getTitle(),
            'description' => $this->getDescription(),
            'content'     => $this->getTemplateContent(),
            'is_custom'   => $this->isCustomized(),
            'plugin'      => self::PLUGIN_NAMESPACE,
        ];
    }

    /**
     * Import template part content.
     *
     * @param string $content The template content to import.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function import($content)
    {
        if (empty($content)) {
            return new \WP_Error('empty_content', __('Template content cannot be empty.', 'fluent-cart'));
        }

        // Delete existing customization
        $this->resetToDefault();

        // Create new template part post
        $post_data = [
            'post_type'    => 'wp_template_part',
            'post_status'  => 'publish',
            'post_title'   => $this->getTitle(),
            'post_name'    => self::SLUG,
            'post_content' => $content,
            'tax_input'    => [
                'wp_theme'              => [get_stylesheet()],
                'wp_template_part_area' => [self::AREA],
            ],
        ];

        $post_id = wp_insert_post($post_data, true);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        // Clear caches
        wp_cache_delete('get_block_templates', 'block_templates');

        return true;
    }
}
