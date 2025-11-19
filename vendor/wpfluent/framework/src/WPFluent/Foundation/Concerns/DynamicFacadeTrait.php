<?php

namespace FluentCart\Framework\Foundation\Concerns;

use FluentCart\Framework\Support\Facade;
use RuntimeException;

trait DynamicFacadeTrait
{
    /**
     * Register the dynamic facade resolver.
     * 
     * Registers an SPL autoloader to intercept facade class requests within
     * the current namespace's Facade sub-namespace. Dynamically
     * creates facade classes on-demand.
     *
     * @param  \FluentCart\Framework\Foundation\Application $app
     * @return void
     */
    protected function registerFacadeResolver($app)
    {
        Facade::setFacadeApplication($app);

        spl_autoload_register(function($class) use ($app) {
            $fqn = __NAMESPACE__;

            $ns = substr($fqn, 0, strpos($fqn, '\\'));

            if (str_contains($class, $facade = $ns . '\Facade')) {
                $this->createFacadeFor($facade, $class, $app);
            }
        }, true, true);
    }

    /**
     * Resolve the binding name for the facade.
     *
     * Transforms the fully qualified facade class name to a service container
     * binding key (accessor) by stripping the facade namespace
     * and normalizing names.
     * 
     * Special cases (e.g. 'route' to 'router') are handled here.
     *
     * @param  string $facade Facade namespace prefix (e.g., FluentCart\Framework\Facade)
     * @param  string $class  Fully qualified facade class name
     * @param  \FluentCart\Framework\Foundation\Application $app
     * @return string|null The resolved accessor name if bound in the container.
     */
    protected function resolveFacadeAccessor($facade, $class, $app)
    {
        $name = strtolower(trim(str_replace($facade, '', $class), '\\'));

        if ($name === 'route') {
            $name = 'router';
        }

        return $app->bound($name) ? $name : null;
    }

    /**
     * Create a facade resolver class dynamically.
     *
     * Generates a PHP facade class file under the uploads directory and loads it.
     * This enables real-time facade creation for any requested class.
     *
     * @param string $facade Facade namespace prefix
     * @param string $class  Fully qualified class name of the facade to generate
     * @param \FluentCart\Framework\Foundation\Application $app
     * @return void
     * @throws \RuntimeException If the facade stub file is missing
     */
    protected function createFacadeFor($facade, $class, $app)
    {
        $accessor = $this->resolveFacadeAccessor($facade, $class, $app);
        [$namespace, $baseClass] = $this->extractNamespaceAndClass($class);
        $slug = $app->config->get('slug');

        $facadesDir = $this->getFacadeDirectory($slug);
        $this->ensureDirectoryExists($facadesDir);

        $facadeFile = $this->getFacadeFilePath($facadesDir, $class);

        if (!file_exists($facadeFile)) {
            $facadeCode = $this->populateFacadeStub(
                $namespace, $baseClass, $accessor
            );

            file_put_contents($facadeFile, $facadeCode);
        }

        $this->loadFacadeClass($class, $facadeFile);
    }

    /**
     * Extract the namespace and base class name from a
     * fully qualified class name (FQCN).
     *
     * @param  string $fqcn Fully qualified class name
     * @return array{string, string} Tuple of namespace and base class name
     */
    protected function extractNamespaceAndClass($fqcn)
    {
        $parts = explode('\\', $fqcn);
        $baseClass = array_pop($parts);
        $namespace = implode('\\', $parts);
        return [$namespace, $baseClass];
    }

    /**
     * Get the directory path where facade classes are stored based on app.slug.
     *
     * @param  string $slug Plugin slug used for folder naming inside uploads
     * @return string The absolute directory path where facade classes are saved
     */
    protected function getFacadeDirectory($slug)
    {
        $uploadDir = wp_upload_dir();
        return "{$uploadDir['basedir']}/{$slug}/facades";
    }

    /**
     * Ensure that the given directory exists; create it recursively if not.
     *
     * @param  string $dir Directory path
     * @return void
     */
    protected function ensureDirectoryExists($dir)
    {
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
    }

    /**
     * Get the full path for the generated facade PHP file based on class name.
     *
     * Converts namespace separators to underscores for safe file names.
     *
     * @param  string $facadesDir Directory where facades are stored
     * @param  string $class      Fully qualified class name
     * @return string Full file path for the facade class file
     */
    protected function getFacadeFilePath($facadesDir, $class)
    {
        $safeName = str_replace(['\\', '/'], '_', $class);
        return "{$facadesDir}/{$safeName}.php";
    }

    /**
     * Populate the facade stub template placeholders with real values.
     *
     * Replaces `{{ namespace }}`, `{{ $stub }}`, `{{ class }}` and
     * `{{ accessor }}` placeholders with corresponding values.
     *
     * @param  string $namespace The namespace for the facade class
     * @param  string $class     The base class name for the facade class
     * @param  string $accessor  The accessor name returned by getFacadeAccessor()
     * @return string The populated PHP class code
     */
    protected function populateFacadeStub($namespace, $class, $accessor)
    {
        return str_replace(
            ['{{ namespace }}', '{{ use }}', '{{ class }}', '{{ accessor }}'],
            [$namespace, Facade::class, $class, $accessor],
            $this->getFacadeStub(__DIR__ . '/facade.stub')
        );
    }

    /**
     * Retrieve the content of the facade stub file.
     *
     * Throws a RuntimeException if the stub file is missing.
     *
     * @param  string $stubPath Absolute path to the stub file
     * @return string The stub file content
     * @throws \RuntimeException If stub file does not exist
     */
    protected function getFacadeStub($stubPath)
    {
        if (!file_exists($stubPath)) {
            throw new RuntimeException(
                "Facade stub not found at: {$stubPath}"
            );
        }

        return file_get_contents($stubPath);
    }

    /**
     * Load the generated facade class file if the class is not already loaded.
     *
     * @param  string $class      Fully qualified class name
     * @param  string $facadeFile Absolute path to the generated facade PHP file
     * @return void
     */
    protected function loadFacadeClass($class, $facadeFile)
    {
        if (!class_exists($class, false)) {
            require_once $facadeFile;
        }
    }
}
