<?php

namespace FluentCart\Framework\Database\Orm;

use Exception;
use FluentCart\Framework\Database\Schema;

trait Searchable
{
    /**
     * The property is intentionally NOT declared on the trait. Declaring it
     * here with a default would make any model that redeclares it with its
     * own columns (protected $searchableColumns = [...]) an incompatible
     * trait composition and fatal in PHP. Models simply declare the property
     * themselves; getSearchableColumns() reads it and defaults to an empty
     * array when a model leaves it unset.
     *
     * @var array
     */

    /**
     * Search the model in a case-insensitive manner.
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @param string $value
     * @return \FluentCart\Framework\Database\Orm\Builder
     * @throws \Exception
     */
    public function scopeSearch($query, $value)
    {
        $columns = $this->ensureSearchableColumns();

        $value = strtolower($value);

        return $query->where(function($query) use ($columns, $value) {
            foreach ($columns as $column) {
                $query->orWhereRaw(
                	'LOWER(' . $column . ') LIKE ?', ['%' . $value . '%']
                );
            }
        });
    }

    /**
     * Search the searchable columns phonetically (SOUNDEX / "sounds like").
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @param string $value
     * @return \FluentCart\Framework\Database\Orm\Builder
     * @throws \Exception
     */
    public function scopeSoundsLike($query, $value)
    {
        $columns = $this->ensureSearchableColumns();

        return $query->where(function($query) use ($columns, $value) {
            foreach ($columns as $column) {
                $query->orWhereSoundsLike($column, $value);
            }
        });
    }

    /**
     * Search the searchable columns by full-text relevance, returning rows
     * ranked by how well they match (a "relevance" column is added).
     *
     * On MySQL/MariaDB this uses native FULLTEXT ranking and requires a
     * FULLTEXT index on the searchable columns (see Schema::fullText()). When
     * $fuzzy is true, recall is widened with a phonetic ("sounds like") match
     * so misspellings still surface (below the ranked hits).
     *
     * SQLite has no native FULLTEXT, so it degrades to a case-insensitive
     * LIKE match across the columns (unranked).
     *
     * @param \FluentCart\Framework\Database\Orm\Builder $query
     * @param string $value
     * @param bool $fuzzy
     * @return \FluentCart\Framework\Database\Orm\Builder
     * @throws \Exception
     */
    public function scopeRelevanceSearch($query, $value, $fuzzy = false)
    {
        $columns = $this->ensureSearchableColumns();

        if (Schema::isSqlite()) {
            return $query->where(function($query) use ($columns, $value, $fuzzy) {
                foreach ($columns as $column) {
                    $query->orWhereLike($column, $value);

                    if ($fuzzy) {
                        $query->orWhereSoundsLike($column, $value);
                    }
                }
            });
        }

        return $query
            ->selectRelevance($columns, $value)
            ->where(function($query) use ($columns, $value, $fuzzy) {
                $query->whereFullText($columns, $value);

                if ($fuzzy) {
                    foreach ($columns as $column) {
                        $query->orWhereSoundsLike($column, $value);
                    }
                }
            })
            ->orderByRelevance();
    }

    /**
     * Get the model's searchable columns, defaulting to an empty array when
     * the model does not declare the $searchableColumns property.
     *
     * @return array
     */
    protected function getSearchableColumns()
    {
        return $this->searchableColumns ?? [];
    }

    /**
     * Ensure searchable columns are defined on the model and return them.
     *
     * @return array
     * @throws \Exception
     */
    protected function ensureSearchableColumns()
    {
        $columns = $this->getSearchableColumns();

        if (empty($columns)) {
            throw new Exception(
                'No searchable columns were defined in ' . get_class($this) . '.'
            );
        }

        return $columns;
    }
}
