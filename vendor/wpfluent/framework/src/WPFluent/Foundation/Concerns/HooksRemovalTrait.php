<?php

namespace FluentCart\Framework\Foundation\Concerns;

trait HooksRemovalTrait
{
	/**
	 * Remove filters/Actions
	 * 
	 * @param  string  $action
	 * @param  string  $class
	 * @param  string  $method
	 * @param  integer $priority
	 * @return bool
	 */
	public function removeFilter($action, $class = '', $method = '', $priority = 10)
    {
        if (is_string($class) && function_exists($class)) {
            return remove_filter(
                $action, $class, is_numeric($method) ? $method : $priority
            );
        }

        $class = is_object($class) ? get_class($class) : $class;
        
        if (!str_contains($class, 'class@anonymous')) {
            
            if (str_contains($class, '@') === false && is_string($method)) {
                $class = $class . '@' . $method;
            }
            
            $handler = $this->parseHookHandler($class);
            
        } else {
            $object = $this->make($class);
            $handler = [$object, $method];
        }

        return $this->removeHook(
        	$action,
        	get_class($handler[0]),
        	$handler[1],
        	is_numeric($method) ? $method : $priority
        );
    }

    /**
	 * Remove filters/Actions
	 * 
	 * @param  string  $action
	 * @param  string  $class
	 * @param  string  $method
	 * @param  integer $priority
	 * @return bool
	 */
    public function removeAction($action, $class = '', $method = '', $priority = 10)
    {
        return $this->removeFilter($action, $class, $method, $priority);
    }

    /**
	 * Remove filters/Actions
	 * 
	 * @param  string  $action
	 * @param  string  $class
	 * @param  string  $method
	 * @param  integer $priority
	 * @return bool
	 */
    public function removeCustomFilter($action, $class = '', $method = '', $priority = 10)
    {
        $prefix = $this->config->get('app.hook_prefix');

        return $this->removeFilter(
            $this->hook($prefix, $action), $class, $method, $priority
        );
    }

    /**
	 * Remove filters/Actions
	 * 
	 * @param  string  $action
	 * @param  string  $class
	 * @param  string  $method
	 * @param  integer $priority
	 * @return bool
	 */
    public function removeCustomAction($action, $class = '', $method = '', $priority = 10)
    {
        $prefix = $this->config->get('app.hook_prefix');

        return $this->removeAction(
            $this->hook($prefix, $action), $class, $method, $priority
        );
    }

    /**
	 * Remove filters/Actions
	 * 
	 * @param  string  $action
	 * @param  string  $class
	 * @param  string  $method
	 * @param  integer $priority
	 * @return bool
	 */
    private function removeHook($action, $class, $method, $priority)
    {
    	global $wp_filter;

        $isHookRemoved = false;

        if (empty($wp_filter[$action]->callbacks[$priority])) {
            return $isHookRemoved;
        }

        $methods = array_filter(wp_list_pluck(
            $wp_filter[$action]->callbacks[$priority], 'function'
        ), function ($method) {
            return is_string($method) || is_array($method);
        });

        $foundHooks = !empty($methods) ? wp_list_filter($methods, [1 => $method]) : [];

        foreach($foundHooks as $hook) {

            if (!empty($hook[0]) && is_object($hook[0]) && get_class($hook[0]) === $class) {

                $wp_filter[$action]->remove_filter($action, $hook, $priority);

                $isHookRemoved = true;
            }
        }

        return $isHookRemoved;
    }
}
