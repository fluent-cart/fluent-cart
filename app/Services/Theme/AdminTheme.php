<?php

namespace FluentCart\App\Services\Theme;

use FluentCart\App\App;

class AdminTheme
{
    const DARK_CLASS = 'fluent_theme_dark';

    public static function applyTheme()
    {
        if ('fluent-cart' === App::request()->get('page')) {
            add_action('admin_head', function () {
                ?>
                <script>
                    (function() {
                        let theme = localStorage.getItem('fluent_theme_mode');

                        if (!theme) {
                            theme = localStorage.getItem('fcart_admin_theme');

                            if (theme) {
                                localStorage.setItem('fluent_theme_mode', theme);
                                localStorage.removeItem('fcart_admin_theme');
                            }
                        }
        
                        if (theme && theme.split(':').pop() === 'dark') {
                            document.documentElement.classList.add('<?php echo esc_js(self::DARK_CLASS); ?>');
                        }
                    })();
                </script>
                <?php
            });
        }
    }
}
