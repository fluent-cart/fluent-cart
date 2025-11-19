<?php

namespace FluentCart\Framework\Database\Query;

use FluentCart\Framework\Database\BaseGrammar;

interface ExpressionInterface
{
    /**
     * Get the value of the expression.
     *
     * @param  \FluentCart\Framework\Database\BaseGrammar $grammar
     * @return string|int|float
     */
    public function getValue(BaseGrammar $grammar);
}
