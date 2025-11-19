<?php

namespace FluentCart\Framework\Foundation\Concerns;

trait AjaxTrait
{
	/**
     * Add ajax action
     * 
     * @param string $action
     * @param string|\Closure $handler
     * @param int $priority
     * @param string $scope
     * @return void
     */
    private function addAjaxAction($action, $handler, $priority, $scope)
    {
        $hook = $scope == 'admin' ? 'wp_ajax_' : 'wp_ajax_nopriv_';

        $action = $hook.$this->config->get('app.hook_prefix').$action;

        $callback = $this->parseHookHandler($handler);

        add_action($action, $callback, $priority);
    }

    /**
     * Add ajax actions including non_prive
     * @param string $action
     * @param string|\Closure $handler
     * @param int $priority
     * @return void
     */
    public function addAjaxActions($action, $handler, $priority = 10)
    {
        $this->addAjaxAction($action, $handler, $priority, 'admin');
        $this->addAjaxAction($action, $handler, $priority, 'public');
    }

    /**
     * Add ajax action for privilaged user
     * @param string $action
     * @param string|\Closure $handler
     * @param int $priority
     * @return void
     */
    public function addAdminAjaxAction($action, $handler, $priority = 10)
    {
        $this->addAjaxAction($action, $handler, $priority, 'admin');
    }

    /**
     * Add ajax action for non-privilaged user
     * @param string $action
     * @param string|\Closure $handler
     * @param int $priority
     * @return void
     */
    public function addPublicAjaxAction($action, $handler, $priority = 10)
    {
        $this->addAjaxAction($action, $handler, $priority, 'public');
    }
}
