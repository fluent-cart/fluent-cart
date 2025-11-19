<?php

namespace FluentCart\Framework\Http;

use Closure;
use ReflectionMethod;
use ReflectionFunction;
use InvalidArgumentException;
use FluentCart\Framework\Container\Util;
use FluentCart\Framework\Support\Reflector;
use FluentCart\Framework\Support\UrlRoutable;

trait SubstituteParameters
{
    protected function SubstituteParameters($routeParameters)
    {
        $resolved = [];

        $signatureParameters = $this->getParametersFromRouteAction();

        if ($signatureParameters) {

            foreach ($signatureParameters as $signatureParam) {

                $name = $signatureParam->getName();
                
                if ($this->boundModel($name, $signatureParam, $routeParameters)) {
                    
                    $class = Util::getParameterClassName($signatureParam);
                    
                    $instance = $this->app->make($class);

                    $resolved[$name] = $instance->resolveRouteBinding(
                        $routeParameters[$name]
                    );

                    unset($routeParameters[$name]);
                }
            }
        }

        $remainingParams = [];

        $signatureParameters = array_filter($signatureParameters, function($param) {
            return !class_exists(Reflector::getParameterClassName($param) ?: '');
        });

        foreach ($routeParameters as $param) {
            if ($dep = array_shift($signatureParameters)) {
                $remainingParams[$dep->getName()] = $param;
            }
        }

        return $resolved + $remainingParams;
    }

    protected function boundModel($name, $parameter, $routeParameters)
    {
        if (array_key_exists($name, $routeParameters)) {
            return Reflector::isParameterSubclassOf(
                $parameter, UrlRoutable::class
            );
        }
    }

    protected function getParametersFromRouteAction()
    {
        if ($this->action instanceof Closure) {
            return (new ReflectionFunction($this->action))->getParameters();
        }

        list($class, $method) = explode('@', $this->action);

        return (new ReflectionMethod($class, $method))->getParameters();
    }
}
