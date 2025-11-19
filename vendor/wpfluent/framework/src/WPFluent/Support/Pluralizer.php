<?php

namespace FluentCart\Framework\Support;

class Pluralizer
{
    /**
     * Plural word form rules.
     *
     * @var array<string,string>
     */
    public static array $plural = [
        '/(quiz)$/i' => '$1zes',
        '/^(ox)$/i' => '$1en',
        '/([ml])ouse$/i' => '$1ice',
        '/(matr|vert|ind)(?:ix|ex)$/i' => '$1ices',
        '/(stoma|epo|monar|matriar|patriar|oligar|eunu)ch$/i' => '$1chs',
        '/(x|ch|ss|sh)$/i' => '$1es',
        '/([^aeiouy]|qu)y$/i' => '$1ies',
        '/(hive)$/i' => '$1s',
        '/(?:([^f])fe|([lr])f)$/i' => '$1$2ves',
        '/(shea|lea|loa|thie)f$/i' => '$1ves',
        '/sis$/i' => 'ses',
        '/([ti])um$/i' => '$1a',
        '/(torped|embarg|tomat|potat|ech|her|vet)o$/i' => '$1oes',
        '/(bu)s$/i' => '$1ses',
        '/(alias)$/i' => '$1es',
        '/(fung)us$/i' => '$1i',
        '/(ax|test)is$/i' => '$1es',
        '/(us)$/i' => '$1es',
        '/s$/i' => 's',
        '/$/' => 's',
    ];

    /**
     * Singular word form rules.
     *
     * @var array<string,string>
     */
    public static array $singular = [
        '/(quiz)zes$/i' => '$1',
        '/(matr)ices$/i' => '$1ix',
        '/(vert|vort|ind)ices$/i' => '$1ex',
        '/^(ox)en$/i' => '$1',
        '/(alias)es$/i' => '$1',
        '/(octop|vir|fung)i$/i' => '$1us',
        '/(cris|ax|test)es$/i' => '$1is',
        '/(shoe)s$/i' => '$1',
        '/(o)es$/i' => '$1',
        '/(bus)es$/i' => '$1',
        '/([ml])ice$/i' => '$1ouse',
        '/(x|ch|ss|sh)es$/i' => '$1',
        '/(m)ovies$/i' => '$1ovie',
        '/(s)eries$/i' => '$1eries',
        '/([^aeiouy]|qu)ies$/i' => '$1y',
        '/([lr])ves$/i' => '$1f',
        '/(tive)s$/i' => '$1',
        '/(hive)s$/i' => '$1',
        '/(li|wi|kni)ves$/i' => '$1fe',
        '/(shea|loa|lea|thie)ves$/i' => '$1f',
        '/(^analy)ses$/i' => '$1sis',
        '/((a)naly|(b)a|(d)iagno|(p)arenthe|(p)rogno|(s)ynop|(t)he)ses$/i' => '$1$2sis',
        '/([ti])a$/i' => '$1um',
        '/(n)ews$/i' => '$1ews',
        '/(h|bl)ouses$/i' => '$1ouse',
        '/(corpse)s$/i' => '$1',
        '/(gallows|headquarters)$/i' => '$1',
        '/(us)es$/i' => '$1',
        '/(us|ss)$/i' => '$1',
        '/s$/i' => '',
    ];

    /**
     * Irregular word forms.
     *
     * @var array<string,string> Singular to plural mappings
     */
    public static array $irregular = [
        'child' => 'children',
        'corpus' => 'corpora',
        'criterion' => 'criteria',
        'foot' => 'feet',
        'freshman' => 'freshmen',
        'goose' => 'geese',
        'genus' => 'genera',
        'human' => 'humans',
        'man' => 'men',
        'woman' => 'women',
        'mouse' => 'mice',
        'louse' => 'lice',
        'person' => 'people',
        'tooth' => 'teeth',
        'ox' => 'oxen',
        'wolf' => 'wolves',
        'calf' => 'calves',
        'leaf' => 'leaves',
        'loaf' => 'loaves',
        'life' => 'lives',
        'wife' => 'wives',
        'knife' => 'knives',
        'self' => 'selves',
        'thief' => 'thieves',
        'datum' => 'data',
        'bacterium' => 'bacteria',
        'medium' => 'media',
        'analysis' => 'analyses',
        'diagnosis' => 'diagnoses',
        'parenthesis' => 'parentheses',
        'hypothesis' => 'hypotheses',
        'thesis' => 'theses',
        'crisis' => 'crises',
        'oasis' => 'oases',
        'phenomenon' => 'phenomena',
        'nucleus' => 'nuclei',
        'radius' => 'radii',
        'stimulus' => 'stimuli',
        'syllabus' => 'syllabi',
        'index' => 'indices',
        'appendix' => 'appendices',
        'matrix' => 'matrices',
        'axis' => 'axes',
        'alumnus' => 'alumni',
        'formula' => 'formulae',
        'move' => 'moves',
        'tax' => 'taxes',
        'tech' => 'techs',
        'cactus' => 'cacti',
        'fungus' => 'fungi',
        'focus' => 'foci',
        'ellipsis' => 'ellipses',
        'basis' => 'bases',
        'vertebra' => 'vertebrae',
        'stratum' => 'strata',
        'hero' => 'heroes',
        'echo' => 'echoes',
        'potato' => 'potatoes',
        'tomato' => 'tomatoes',
    ];

    /**
     * Uncountable word forms.
     *
     * @var string[]
     */
    public static array $uncountable = [
        'advice',
        'air',
        'alcohol',
        'art',
        'bison',
        'bread',
        'butter',
        'cheese',
        'chassis',
        'clothing',
        'commerce',
        'compensation',
        'coreopsis',
        'data',
        'deer',
        'dust',
        'education',
        'electricity',
        'equipment',
        'evidence',
        'fashion',
        'feedback',
        'fish',
        'flour',
        'food',
        'furniture',
        'garbage',
        'gold',
        'grass',
        'happiness',
        'homework',
        'honesty',
        'information',
        'jewelry',
        'knowledge',
        'luggage',
        'mathematics',
        'meat',
        'money',
        'moose',
        'music',
        'news',
        'nutrition',
        'offspring',
        'oil',
        'oxygen',
        'patience',
        'permission',
        'plankton',
        'poetry',
        'police',
        'progress',
        'rain',
        'rice',
        'salt',
        'series',
        'sheep',
        'shopping',
        'software',
        'species',
        'sugar',
        'swine',
        'tea',
        'traffic',
        'transportation',
        'water',
        'weather',
        'wildlife',
        'wood',
        'wool',
        'work',
    ];

    /**
     * Cache for plural inflections.
     *
     * @var array<string,string>
     */
    protected static array $pluralCache = [];

    /**
     * Cache for singular inflections.
     *
     * @var array<string,string>
     */
    protected static array $singularCache = [];

    /**
     * Convert a plural word to its singular form.
     *
     * @param  string $value
     * @return string
     */
    public static function singular(string $value): string
    {
        if (isset(static::$singularCache[$value])) {
            return static::$singularCache[$value];
        }

        $result = static::inflect($value, static::$singular, static::$irregular);

        return static::$singularCache[$value] = $result ?: $value;
    }

    /**
     * Convert a singular word to its plural form.
     *
     * @param  string $value
     * @param  int $count Number of items; if 1, returns singular.
     * @return string
     */
    public static function plural(string $value, int $count = 2): string
    {
        if ($count === 1) {
            return $value;
        }

        if (in_array($value, static::$irregular, true)) {
            return $value;
        }

        if (isset(static::$pluralCache[$value])) {
            return static::$pluralCache[$value];
        }

        $irregular = array_flip(static::$irregular);
        $result = static::inflect($value, static::$plural, $irregular);

        return static::$pluralCache[$value] = $result;
    }

    /**
     * Perform inflection (singular or plural) on a word.
     *
     * @param  string $value
     * @param  array<string,string> $source
     * @param  array<string,string> $irregulars
     * @return string|null
     */
    protected static function inflect(string $value, array $source, array $irregulars): ?string
    {
        if (static::uncountable($value)) {
            return $value;
        }

        foreach ($irregulars as $irregular => $pattern) {
            if (preg_match($pattern = '/'.$pattern.'$/i', $value)) {
                $irregular = static::matchCase($irregular, $value);
                return preg_replace($pattern, $irregular, $value);
            }
        }

        foreach ($source as $pattern => $inflected) {
            if (preg_match($pattern, $value)) {
                $inflected = preg_replace($pattern, $inflected, $value);
                return static::matchCase($inflected, $value);
            }
        }

        return null;
    }

    /**
     * Check if a word is uncountable.
     *
     * @param  string $value
     * @return bool
     */
    protected static function uncountable(string $value): bool
    {
        return in_array(strtolower($value), static::$uncountable, true);
    }

    /**
     * Match the case of a word to another word's case.
     *
     * @param  string $value
     * @param  string $comparison
     * @return string
     */
    protected static function matchCase(string $value, string $comparison): string
    {
        $functions = ['mb_strtolower', 'mb_strtoupper', 'ucfirst', 'ucwords'];

        foreach ($functions as $function) {
            if (call_user_func($function, $comparison) === $comparison) {
                return call_user_func($function, $value);
            }
        }

        return $value;
    }

    /**
     * Check if a word is plural.
     *
     * @param  string $word
     * @return bool
     */
    public static function isPlural(string $word): bool
    {
        return static::singular($word) !== $word;
    }

    /**
     * Check if a word is singular.
     *
     * @param  string $word
     * @return bool
     */
    public static function isSingular(string $word): bool
    {
        return static::singular($word) === $word;
    }

    /**
     * Pluralize a word only if needed based on count.
     *
     * @param  string $word
     * @param  int $count
     * @return string
     */
    public static function pluralizeIfNeeded(string $word, int $count): string
    {
        return $count === 1 ? $word : static::plural($word, $count);
    }

    /**
     * Add a new irregular singular/plural pair.
     *
     * @param  string $singular
     * @param  string $plural
     * @return void
     */
    public static function addIrregular(string $singular, string $plural): void
    {
        static::$irregular[$singular] = $plural;
        static::clearCache();
    }

    /**
     * Add a new uncountable word.
     *
     * @param  string $word
     * @return void
     */
    public static function addUncountable(string $word): void
    {
        $word = strtolower($word);
        if (!in_array($word, static::$uncountable, true)) {
            static::$uncountable[] = $word;
            static::clearCache();
        }
    }

    /**
     * Clear the plural and singular caches.
     *
     * @return void
     */
    public static function clearCache(): void
    {
        static::$pluralCache = [];
        static::$singularCache = [];
    }
}
