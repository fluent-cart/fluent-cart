<?php

namespace FluentCart\Database\Overrides;


use FluentCart\Framework\Database\Query\WPDBConnection;

class DbConnection extends WPDBConnection
{
    public function query()
    {
        return new QueryBuilder(
            $this, $this->getQueryGrammar(), $this->getPostProcessor()
        );
    }
}