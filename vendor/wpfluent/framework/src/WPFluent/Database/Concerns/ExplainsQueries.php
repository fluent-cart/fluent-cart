<?php

namespace FluentCart\Framework\Database\Concerns;

use FluentCart\Framework\Support\Collection;

trait ExplainsQueries
{
    /**
     * Explains the query.
     *
     * @return \FluentCart\Framework\Support\Collection
     */
    public function explain()
    {
        $sql = $this->toSql();

        $bindings = $this->getBindings();

        $explanation = $this->getConnection()->select('EXPLAIN '.$sql, $bindings);

        return new Collection($explanation);
    }
}
