<?php

namespace FluentCart\App\Models\WpModels;

use FluentCart\App\Models\Concerns\CanSearch;
use FluentCart\App\Models\Model;
use FluentCart\App\Models\Product;

class TermTaxonomy extends Model
{
    use CanSearch;
    protected $table = 'term_taxonomy';
    protected $primaryKey = 'term_id';


    public function termRelationships()
    {
        return $this->hasMany(TermRelationship::class, 'term_taxonomy_id', 'term_taxonomy_id');
    }

    /**
     * Get the term that owns the taxonomy
     */
    public function term()
    {
        return $this->belongsTo(Term::class, 'term_id', 'term_id');
    }

    /**
     * Get the parent taxonomy
     */
    public function parent()
    {
        return $this->belongsTo(TermTaxonomy::class, 'parent', 'term_taxonomy_id');
    }

    public function taxonomy()
    {
        return $this->belongsTo(Term::class, 'term_id', 'term_id');
    }


    /**
     * Get child taxonomies
     */
    public function children()
    {
        return $this->hasMany(TermTaxonomy::class, 'parent', 'term_taxonomy_id');
    }

    /**
     * Get all relationships for this taxonomy
     */
    public function relationships()
    {
        return $this->hasMany(TermRelationship::class, 'term_taxonomy_id', 'term_taxonomy_id');
    }

    /**
     * Get all products for this taxonomy
     */
    public function products()
    {
        return $this->hasManyThrough(
            Product::class,
            TermRelationship::class,
            'term_taxonomy_id', // Foreign key on term_relationships
            'ID', // Foreign key on products
            'term_taxonomy_id', // Local key on term_taxonomy
            'object_id' // Local key on term_relationships
        );
    }
}
