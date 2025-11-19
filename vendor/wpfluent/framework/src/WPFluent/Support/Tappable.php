<?php

namespace FluentCart\Framework\Support;

trait Tappable
{
    /**
     * Call the given Closure with this instance then return the instance.
     *
     * @param  callable|null  $callback
     * @return $this|\FluentCart\Framework\Support\HigherOrderTapProxy
     */
    public function tap($callback = null)
    {
        return Helper::tap($this, $callback);
    }
}
