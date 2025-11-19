<?php

namespace FluentCart\Framework\View;

use Exception;
use LogicException;

class View
{
	/**
     * Application Instance
     * @var \FluentCart\Framework\Foundation\Application $app
     */
	protected $app;

	/**
	 * View file path
	 * 
	 * @var string
	 */
	protected $path;

	/**
	 * View data
	 * @var array
	 */
	protected $data = [];
	
	/**
	 * Shared data inall the views
	 * @var array
	 */
	protected static $sharedData = [];

	/**
	 * Construct the view instamce
	 * @param \FluentCart\Framework\Foundation\Application $app
	 */
	public function __construct($app)
	{
		$this->app = $app;
	}

	/**
	 * Generate and echo/print a view file
	 * @param  string $path
	 * @param  array  $data
	 * @return void
	 */
	public function render($path, $data = [])
	{
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo $this->make($path, $data);
	}

	/**
	 * Generate a view file
	 * @param  string $path
	 * @param  array  $data
	 * @return string [generated html]
	 * @throws Exception
	 */
	public function make($path, $data = [])
	{
		$path = str_replace('.php', '', $path);

		// If full path is given, then just use it
		if (file_exists($this->path = $path . '.php')) {
			return $this->prepareData($data);
		}

        if (strpos($path, ':')) {
            return $this->loadViewFrom($path, $data);
        }

		if (file_exists($this->resolveFilePath($path))) {
			return $this->prepareData($data);
		}

		$this->handleViewNotFoundException($this->path);
	}

	/**
	 * Load view from specified location.
	 * 
	 * @param  string $path
	 * @param  array $data
	 * @return string
	 * @throws Exception
	 */
	public function loadViewFrom($path, $data)
	{
		$pieces = explode(':', $path);
		
		$pluginRoot = reset($pieces);
		
		if (is_dir($root = WP_PLUGIN_DIR . '/' . $pluginRoot)) {
            
            if (is_dir($views = $root . '/app/Views')) {

                $path = $views . '/' . end($pieces);
        
                $path = str_replace('.', DIRECTORY_SEPARATOR, $path);

                if (file_exists($this->path = ($path . '.php'))) {
                    return $this->prepareData($data);
                }       
            }
        }
		
        $this->handleViewNotFoundException($path);
	}

    /**
     * Throw view not found exception.
     * 
     * @return void
     * @throws \Exception
     */
    protected function handleViewNotFoundException($path)
    {
        throw new Exception("The view file [{$path}] doesn't exists!");
    }

    /**
     * Prepare data for view.
     * 
     * @param  array $data
     * @return self
     */
    protected function prepareData($data)
    {
        $this->data = array_merge(
            $this->data, static::$sharedData, $data
        );

        return $this;
    }

	/**
	 * Resolve the view file path
	 * @param  string $path
	 * @return string
	 */
	protected function resolveFilePath($path)
	{
        $path = str_replace('.', DIRECTORY_SEPARATOR, $path);

        return $this->path = $this->app['path.views'] . $path .'.php';
	}

	/**
	 * Evaluate the view file
	 * @param  \FluentCart\Framework\Foundation\Application $app
	 * @return $this
	 */
	protected function renderContent($app)
	{
		ob_start();
		extract($this->data, EXTR_SKIP);
		require $this->getNormalizedPath();
		return ltrim(ob_get_clean());
	}

    /**
     * Get normalized path.
     * 
     * @return string
     */
    protected function getNormalizedPath()
    {
        $ds = DIRECTORY_SEPARATOR;

        $path = str_replace($ds.$ds, $ds, $this->path);

        return $path;
    }

	/**
	 * Share global data for any view
	 * @param  string $key
	 * @param  mixed $value
	 * @return void
	 */
	public function share($key, $value)
	{
		static::$sharedData[$key] = $value;
	}

	/**
	 * Provides a fluent interface to set data
	 * @param  array|string $name
	 * @param  mixed $data
	 * @return $this
	 */
	public function with($name, $data = [])
	{
		if (is_array($name)) {
			foreach ($name as $key => $value) {
				$this->__set($key, $value);
			}
		} else {
			$this->__set($name, $data);
		}
		
		return $this;
	}

	/**
	 * Set view path (used for micro).
	 * 
	 * @param string $path
	 */
	public function setViewPath($path)
	{
		$this->app['path.views'] = $path;
	}

	/**
	 * Get the resolved view path.
	 * 
	 * @return string
	 */
	public function getResolvedPath()
	{
		return $this->path;
	}

	/**
	 * Getter for the view.
	 * 
	 * @param string $key
	 */
	public function __get($key)
	{
		if (isset($this->data[$key])) {
			return $this->data[$key];
		}
	}

	/**
	 * Setter for the view
	 * @param string $key
	 * @param mixed $value
	 */
	public function __set($key, $value)
	{
		$this->data[$key] = $value;
	}

	/**
	 * Return the view data.
	 * 
	 * @return array
	 */
	public function toArray()
	{
		return $this->data;
	}

	/**
	 * Dump the view result.
	 * 
	 * @return string
	 */
	public function __toString()
	{
		return $this->renderContent($this->app);
	}

    /**
     * Prevent unserialization (legacy PHP <7.4)
     */
    public function __wakeup()
    {
        $this->handleUnserializeNotAllowedException();
    }

    /**
     * Prevent unserialization (PHP >=7.4)
     */
    public function __unserialize(array $data)
    {
        $this->handleUnserializeNotAllowedException();
    }

    /**
     * Handle unserialization error.
     * 
     * @return void
     * @throws \LogicException
     */
    protected function handleUnserializeNotAllowedException()
    {
        throw new LogicException(static::class . ' cannot be unserialized.');
    }
}
