<?php

namespace FluentCart\App\Services\ShortCodeParser\Parsers;

use FluentCart\App\Services\ShortCodeParser\Contracts\ParserContract;
use FluentCart\App\Services\ShortCodeParser\ValueTransformer;
use FluentCart\Framework\Support\Arr;
use FluentCart\Framework\Support\Str;

abstract class BaseParser implements ParserContract
{

    use ValueTransformer;

    protected $data = null;

    /**
     * @var array $instances
     *
     * This static property is used to cache the results of method and attribute lookups.
     *
     * The `BaseParser` class uses this array to store the values retrieved by the `get` method.
     * When a value is requested for a specific code, the `get` method first checks if the value
     * is already cached in this array. If it is, the cached value is returned, avoiding redundant
     * lookups or method calls.
     *
     * This caching mechanism improves performance by reducing the number of times the same
     * data needs to be retrieved or computed.
     */
    protected static array $instances = [];

    /**
     * @var array $methodMap
     *
     * This property maps codes to their corresponding methods.
     *
     * The `BaseParser` class uses this array to determine which method to call
     * when a specific code is requested. The keys in this array are the codes,
     * and the values are the names of the methods to be called.
     *
     * For example, if the `methodMap` contains an entry `'affiliate_url' => 'getAffiliateUrl'`,
     * calling `get('affiliate_url')` will invoke the `getAffiliateUrl` method.
     */
    protected array $methodMap = [];

    /**
     * @var array $attributeMap
     *
     * This property maps codes to their corresponding attributes in the data array.
     *
     * The `BaseParser` class uses this array to determine which attribute to retrieve
     * when a specific code is requested. The keys in this array are the codes, and the values
     * are the keys in the data array where the corresponding values can be found.
     *
     * For example, if the `attributeMap` contains an entry `'affiliate_name' => 'user_details.full_name'`,
     * calling `get('affiliate_name')` will retrieve the value stored in `$data['user_details']['full_name']`.
     */
    protected array $attributeMap = [];

    public function __construct($data)
    {
        $this->data = $data;
    }

    /**
     * Abstract method to parse the given code.
     *
     * This method must be implemented by any subclass to define
     * how the parsing of the given code should be handled.
     *
     * @param null|string $accessor
     * @param null|string $template
     * @return string The parsed result.
     */
    abstract public function parse($accessor = null, $template = null): ?string;

    /**
     * Retrieves the value for the given code.
     *
     * This method first checks if the value for the given code is already cached in the `static::$instances` array.
     * If it is, the cached value is returned. If not, it checks if the code corresponds to a method or an attribute.
     * If the code corresponds to a method, the `getByMethod` method is called to retrieve the value.
     * If the code corresponds to an attribute, the `getAttribute` method is called to retrieve the value.
     * If the code does not correspond to any method or attribute, a placeholder string with the code is returned.
     *
     * @param string|null $accessor The code representing the method or attribute to be retrieved.
     * @param string|null $template The code representing the method or attribute to be retrieved.
     * @return string The value associated with the given code, or a placeholder string if the code is not found.
     */
    public function get(?string $accessor, ?string $template = null): ?string
    {


        $conditions = $this->evaluateCondition($template);

        //update the template after parsing $conditions
        //order.payment_method||title_case -> to order.payment_method
        $template = $conditions['accessor'];

        if (isset(static::$instances[$accessor])) {
            return static::$instances[$accessor];
        }

        if (isset($this->methodMap[$accessor])) {
            return $this->getByMethod($accessor, $template, $conditions);
        }

        if (isset($this->attributeMap[$accessor])) {
            return $this->getAttribute($accessor);
        }

        if (method_exists($this, 'get' . Str::studly($accessor))) {
            $methodName = 'get' . Str::studly($accessor);
            return $this->{$methodName}($accessor, $template, $conditions);
        }


        return Arr::get($this->data, $accessor) ??
            Arr::get($this->data, $template);
    }

    /**
     * Retrieves the value for the given code from the data array.
     *
     * This method uses the `attributeMap` array to find the corresponding key in the data array
     * and retrieves its value. The result is then stored in the `static::$instances` array
     * to avoid redundant lookups in the future.
     *
     * @param string $code The code representing the attribute to be retrieved.
     * @return string The value of the attribute associated with the given code.
     */
    protected function getAttribute(string $code): string
    {
        static::$instances[$code] = Arr::get($this->data, $this->attributeMap[$code]);
        return static::$instances[$code];
    }

    /**
     * Retrieves the value for the given code by invoking the corresponding method.
     *
     * This method checks the `methodMap` array for the provided code and calls the
     * associated method. The result is then stored in the `static::$instances` array
     * to avoid redundant method calls in the future.
     *
     * @param string $accessor The code representing the method to be called.
     * @param ?string $template The full shortcode.
     * @return string The result of the method call associated with the given code.
     */
    protected function getByMethod(string $accessor, ?string $template, $conditions = []): ?string
    {
        static::$instances[$accessor] = $this->{$this->methodMap[$accessor]}($accessor, $template, $conditions);
        return static::$instances[$accessor];
    }
}
