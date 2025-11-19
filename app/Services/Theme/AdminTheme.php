<?php

namespace FluentCart\App\Services\Theme;

use FluentCart\App\App;

class AdminTheme
{
    public static function applyTheme()
    {
        if ('fluent-cart' === App::request()->get('page')) {
            add_action('admin_head', function () {
                ?>
                <script>
                    (function() {
                        const theme = localStorage.getItem('fcart_admin_theme');
        
                        if (theme) {
                            document.documentElement.classList.add(theme.split(':').pop());
                        }
                    })();
                </script>
                <?php
            });
        }
    }
}