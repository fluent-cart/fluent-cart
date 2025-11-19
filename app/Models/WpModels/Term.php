<?php

namespace FluentCart\App\Models\WpModels;

use FluentCart\App\Models\Model;
use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Models\Product;

class Term extends Model
{
    use CanSearch;
    
    protected $table = 'terms';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'term_id';

    public function taxonomy(): \FluentCart\Framework\Database\Orm\Relations\HasOne
    {
        return $this->hasOne(TermTaxonomy::class, 'term_id', 'term_id');
    }

    /**
     * Get the taxonomies for the term
     */
    public function taxonomies()
    {
        return $this->hasMany(TermTaxonomy::class, 'term_id', 'term_id');
    }


    /**
     * Get products associated with this term
     */
    public function products()
    {
        return $this->hasManyThrough(
            Product::class,
            TermRelationship::class,
            'term_taxonomy_id', // Foreign key on term_relationships
            'ID', // Foreign key on products
            'term_id', // Local key on terms
            'object_id' // Local key on term_relationships
        )->join('term_taxonomy', 'term_relationships.term_taxonomy_id', '=', 'term_taxonomy.term_taxonomy_id')
            ->where('term_taxonomy.term_id', $this->term_id);
    }

}
