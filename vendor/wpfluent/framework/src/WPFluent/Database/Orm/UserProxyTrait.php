<?php

namespace FluentCart\Framework\Database\Orm;

use WP_User;
use BadMethodCallException;
use InvalidArgumentException;
use FluentCart\Framework\Http\Request\WPUserProxy;

trait UserProxyTrait
{
    /**
     * Resolve the WPUserProxy instance.
     *
     * Always returns a live WPUserProxy reflecting latest WP_User data.
     *
     * @return WPUserProxy
     */
    protected function resolveWPUser()
	{
	    $userIdentifier = $this->getKey() ?: wp_get_current_user();

	    return new WPUserProxy($userIdentifier);
	}

    /**
     * Dual-purpose is() method:
     *  - is(string $role) - checks user role
     *  - is(User $model) - checks model identity
     *
     * @param string|self $value
     * @return bool
     */
    public function is($value)
    {
        if (is_string($value)) {
            return $this->resolveWPUser()->is($value);
        }

        if ($value instanceof static) {
            return parent::is($value);
        }

        return false;
    }

    /**
     * Dynamically call methods on the WPUserProxy if not found in the model.
     *
     * @param string $method
     * @param array $args
     * @return mixed
     *
     * @throws BadMethodCallException|InvalidArgumentException
     */
    public function __call($method, $args)
    {
        try {
            return parent::__call($method, $args);
        } catch (BadMethodCallException $e) {
            try {
                return $this->resolveWPUser()->$method(...$args);
            } catch (BadMethodCallException $proxyError) {
                throw $proxyError;
            } catch (InvalidArgumentException $invalidUser) {
                throw new BadMethodCallException(
                    "Call to undefined method " . static::class . "::{$method}()",
                    0,
                    $invalidUser
                );
            }
        }
    }
}
