<?php

namespace FluentCart\Framework\Foundation\Concerns;

use FluentCart\Framework\Http\Request\WPUserProxy;

trait FoundationTrait
{
    use AjaxTrait, HooksRemovalTrait;

    /**
     * Check if wp debug mode is on.
     * 
     * @return boolean
     */
    public function isDebugOn()
    {
        return defined('WP_DEBUG') && WP_DEBUG;
    }

    /**
     * Check whether multi-site or not.
     * 
     * @return boolean
     */
    public function isMultiSite()
    {
        return function_exists('is_multisite') && is_multisite();
    }

    /**
     * Determine the environment.
     * 
     * @return string
     */
    public function env()
    {
        if (php_sapi_name() === 'cli' && isset($_ENV['WPF_ENV'])) {
            $env = $_ENV['WPF_ENV'];
        } else {
            $env = $this->isDebugOn() ? 'dev' : '';
        }
        
        return $env ?: $this->config->get('app.env', 'prod');
    }

    /**
     * Get current namespace for the plugin.
     * 
     * @return string
     */
    public function getCurrentNamespace()
    {
        return $this->getComposer('extra.wpfluent.namespace.current');
    }

    /**
     * Make the custom hook name for the plugin.
     * @param  string $prefix
     * @param  string $hook
     * @return string
     */
    public function hook($prefix, $hook)
    {
        return $prefix . $hook;
    }

    /**
     * Parse the handler for the rest request
     * @param  string|\Closure $handler
     * @param  string $ns
     * @return mixed
     */
    public function parseRestHandler($handler, $ns = '')
    {
        if ($handler instanceof \Closure) {
            return $handler;
        }

        if (is_array($handler)) {

            if (is_object($handler[0])) {
                return $handler;
            }

            if (is_string($handler[0])) {
                $handler = $handler[0] . '@' . $handler[1];
            }
        }

        if ($ns && str_contains($handler, $ns)) {
            if (strpos($handler, $ns) !== false) {
                $handler = trim(str_replace($ns, '', $handler), '\\');
            }
        }
        
        $handler = $ns ? $ns . '\\' . $handler : $handler;

        return $this->getControllerNamespace($handler) . '\\' . $handler;
    }

    /**
     * Parse the policy handler
     * @param  string|array $handler
     * @return mixed
     */
    public function parsePolicyHandler($handler)
    {
        if (is_string($handler)) {

            if (function_exists($handler)) return $handler;

            $handler = ltrim($handler, '\\');

            $handler = $this->getPolicyNamespace($handler) . '\\' . $handler;

            if (is_string($handler) && strpos($handler, '@') !== false) {

                list($class, $method) = explode('@', $handler);

                if (!method_exists($class, $method)) {
                    $method = 'verifyRequest';
                }

                $instance = $this->make($class);

                $handler = [$instance, $method];
            }

        } else if (is_array($handler)) {
            list($class, $method) = $handler;

            if (is_string($class)) {
                $handler = $this->getPolicyNamespace($handler) . '\\' . $class . '::' . $method;
            }
        }

        return $handler;
    }

    /**
     * Register an action hook
     * @param string $action
     * @param mixed $handler
     * @param integer $priority
     * @param integer $numOfArgs
     */
    public function addAction($action, $handler, $priority = 10, $numOfArgs = 1)
    {
        return add_action(
            $action,
            $this->parseHookHandler($handler),
            $priority,
            $numOfArgs
        );
    }

    /**
     * Register a custom action hook
     * @param string $action
     * @param mixed $handler
     * @param integer $priority
     * @param integer $numOfArgs
     */
    public function addCustomAction($action, $handler, $priority = 10, $numOfArgs = 1)
    {
        $prefix = $this->config->get('app.hook_prefix');

        return $this->addAction(
            $this->hook($prefix, $action), $handler, $priority, $numOfArgs
        );
    }

    /**
     * Dispatch an action
     * @return null
     */
    public function doAction()
    {
        return call_user_func_array('do_action', func_get_args());
    }

    /**
     * Dispatch a custom action
     * @return null
     */
    public function doCustomAction()
    {
        $args = func_get_args();

        $prefix = $this->config->get('app.hook_prefix');

        $args[0] = $this->hook($prefix, $args[0]);

        return call_user_func_array('do_action', $args);
    }

    /**
     * Register a filter hook
     * @param string $action
     * @param mixed $handler
     * @param integer $priority
     * @param integer $numOfArgs
     */
    public function addFilter($action, $handler, $priority = 10, $numOfArgs = 1)
    {
        return add_filter(
            $action,
            $this->parseHookHandler($handler),
            $priority,
            $numOfArgs
        );
    }

    /**
     * Register a custom filter hook
     * @param string $action
     * @param mixed $handler
     * @param integer $priority
     * @param integer $numOfArgs
     */
    public function addCustomFilter($action, $handler, $priority = 10, $numOfArgs = 1)
    {
        $prefix = $this->config->get('app.hook_prefix');

        return $this->addFilter(
            $this->hook($prefix, $action), $handler, $priority, $numOfArgs
        );
    }

    /**
     * Dispatc a filter
     * @return mixed
     */
    public function applyFilters()
    {
        return call_user_func_array('apply_filters', func_get_args());
    }

    /**
     * Dispatch a custom filter
     * @return mixed
     */
    public function applyCustomFilters()
    {
        $args = func_get_args();
        $prefix = $this->config->get('app.hook_prefix');
        $args[0] = $this->hook($prefix, $args[0]);

        return call_user_func_array('apply_filters', $args);
    }

    /**
     * Checks if any action has been fired.
     * 
     * @param  string $action
     * @return int Number of times the action was fired
     */
    public function didAction($action)
    {
        return did_action($action);
    }

    /**
     * Checks if any action has been registered.
     * 
     * @param  string $action
     * @param  mixed $callback
     * @return bool
     */
    public function hasAction($action, $callback = false)
    {   
        return has_action($action, $callback);
    }

    /**
     * Checks if any custom action has been fired.
     * 
     * @param  string $action
     * @return int Number of times the action was fired
     */
    public function didCustomAction($action)
    {
        return did_action(
            $this->config->get('app.hook_prefix') . $action
        );
    }

    /**
     * Checks if any custom action has been registered.
     * 
     * @param  string $action
     * @param  mixed $callback
     * @return bool
     */
    public function hasCustomAction($action, $callback = false)
    {
        $prefix = $this->config->get('app.hook_prefix');
        
        return has_action($prefix.$action, $callback);
    }

    /**
     * Checks if any action has been fired.
     * 
     * @param  string $action
     * @return int Number of times the action was fired
     */
    public function didFilter($action)
    {
        return did_filter($action);
    }

    /**
     * Checks if any action has been registered.
     * 
     * @param  string $action
     * @param  mixed $callback
     * @return bool
     */
    public function hasFilter($action, $callback = false)
    {   
        return has_filter($action, $callback);
    }

    /**
     * Checks if any custom action has been fired.
     * 
     * @param  string $action
     * @return int Number of times the action was fired
     */
    public function didCustomFilter($action)
    {
        return did_filter(
            $this->config->get('app.hook_prefix') . $action
        );
    }

    /**
     * Checks if any custom action has been registered.
     * 
     * @param  string $action
     * @param  mixed $callback
     * @return bool
     */
    public function hasCustomFilter($action, $callback = false)
    {
        $prefix = $this->config->get('app.hook_prefix');
        
        return has_filter($prefix.$action, $callback);
    }

    /**
     * Register a short code
     * @param string $action
     * @param string|\Closure $handler
     * @return void
     */
    public function addShortcode($action, $handler)
    {
        add_shortcode($action, $this->parseHookHandler($handler));
    }

    /**
     * Execute a shortcode
     * @param  mixed $content
     * @param  boolean $ignore_html
     * @return mixed
     */
    public function doShortcode($content, $ignore_html = false)
    {
        return do_shortcode($content, $ignore_html);
    }

    /**
     * Parse a hookm handler
     * @param  mixed $handler
     * @return mixed
     */
    public function parseHookHandler($handler)
    {
        if (is_string($handler)) {

            if (function_exists($handler)) return $handler;
            
            if (count($array = preg_split('/::|@/', $handler)) < 2) {
                $array[] = 'handle';
            }

            list($class, $method) = $array;

            $class = $this->makeInstance($class);

            return is_callable($class) ? $class : [$class, $method];

        } else if (is_array($handler)) {
            list($class, $method) = $handler;
            if (is_string($class)) {
                $class = $this->makeInstance($class);
            }
            return [$class, $method];
        }

        return $handler;
    }

    /**
     * Chdeck if handler has FQCN.
     * 
     * @param  string|\Closure $handler
     * @return boolean
     */
    public function hasNamespace($handler)
    {
        if ($handler instanceof \Closure) {
            return false;
        };
        
        $parts = array_filter(explode('\\', $handler));
        
        return count($parts) > 1;
    }

    /**
     * Resolve the namespace for a controller
     * @param  string $handler
     * @return mixed
     */
    public function getControllerNamespace($handler)
    {
        if ($this->hasNamespace($handler)) {
            return '';
        }

        return $this->controllerNamespace;
    }

    /**
     * Resolve the namespace for a policy
     * @param  string $handler
     * @return mixed
     */
    public function getPolicyNamespace($handler)
    {
        if ($this->hasNamespace($handler)) {
            return '';
        }

        return $this->policyNamespace;
    }

    /**
     * Make an instance by the container
     * @param  string $class
     * @return mixed
     */
    public function makeInstance($class)
    {
        if ($this->hasNamespace($class)) {
            $instance = $this->make($class);
        } else {
            $instance = $this->make($this->handlerNamespace . '\\' . $class);
        }

        return $instance;
    }

    /**
     * Retrieve the base url
     * @param  string $url
     * @return string
     */
    public function url($url = '')
    {
        return $this->baseUrl.ltrim($url, '/');
    }

    /**
     * Get the current authenticated user.
     *
     * Returns a WPUserProxy instance if the 'init' action has fired,
     * otherwise logs a warning in debug mode and returns null.
     * 
     * @return mixed
     */
    public function user()
    {
        if (!did_action('init')) {
            return new class {
                public function __call($method, $args) {}
            };
        }

        $wpUser = wp_get_current_user();

        if (!$wpUser->ID) {
            return new class {
                public function __call($method, $args) {}
            };
        }

        $userModel = $this->__namespace__ . '\App\Models\User';

        return class_exists($userModel)
            ? ($userModel::find($wpUser->ID) ?? new WPUserProxy($wpUser))
            : new WPUserProxy($wpUser);
    }
}
