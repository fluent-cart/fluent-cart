<?php

namespace FluentCart\App\Modules\Shipping\Carriers\Core;

use FluentCart\App\Models\Meta;
use FluentCart\Framework\Support\Arr;

abstract class BaseCarrierSettings
{
    /**
     * All carrier settings rows are loaded once per request (mirrors
     * BaseGatewaySettings) so each registered carrier does not issue
     * its own query on fluentcart_loaded.
     */
    protected static array $allSettings = [];
    protected static bool $settingsLoaded = false;

    protected string $metaKey = '';

    /**
     * Null until first access. Every carrier is constructed on `fluentcart_loaded`
     * — i.e. on every request, checkout and frontend page included — so querying
     * in the constructor billed one SELECT to page views that never read a
     * setting. Resolution is deferred to the first get()/save() instead.
     */
    protected ?array $settings = null;

    abstract public function getDefaults(): array;

    /**
     * Resolves this carrier's settings, populating the shared per-request cache
     * with ONE query the first time any carrier asks for anything.
     */
    protected function loadSettings(): void
    {
        if ($this->settings !== null) {
            return;
        }

        if (!$this->metaKey) {
            _doing_it_wrong(
                __METHOD__,
                sprintf('%s must define a non-empty $metaKey.', esc_html(get_class($this))),
                esc_html(FLUENTCART_VERSION)
            );
            $this->settings = $this->getDefaults();
            return;
        }

        if (!self::$settingsLoaded) {
            // Descending, deliberately: pluck() keys by meta_key and lets later rows
            // overwrite earlier ones, so ordering DESC makes the LOWEST id the final
            // write. That is the row fluent_cart_update_option() keeps when it
            // collapses duplicates, so a duplicated key reads the same value the
            // writer maintains instead of resolving in whatever order the DB returns.
            self::$allSettings = Meta::query()
                ->whereLike('meta_key', 'fluent_cart_carrier_settings\\_%')
                ->where('object_type', 'option')
                ->orderBy('id', 'DESC')
                ->get()
                ->pluck('meta_value', 'meta_key')
                ->toArray();

            self::$settingsLoaded = true;
        }

        $saved = Arr::get(self::$allSettings, $this->metaKey, []);
        if (!is_array($saved)) {
            $saved = [];
        }

        $this->settings = wp_parse_args($saved, $this->getDefaults());
    }

    public function get($key = '')
    {
        $this->loadSettings();

        if ($key === '') {
            return $this->settings;
        }
        return isset($this->settings[$key]) ? $this->settings[$key] : null;
    }

    public function save(array $data): void
    {
        $this->loadSettings();

        if (!$this->metaKey) {
            return;
        }

        // Merge into the CURRENT settings, not the defaults — keys absent from
        // a partial update (e.g. masked password fields the UI never sends
        // back) must keep their stored value instead of resetting.
        $merged = wp_parse_args($data, $this->settings);
        $this->settings = $merged;
        self::$allSettings[$this->metaKey] = $merged;

        fluent_cart_update_option($this->metaKey, $merged);

        $this->normalizeStoredRow($merged);
    }

    /**
     * Guarantees this carrier's settings live in exactly one row, holding the value
     * we just wrote.
     *
     * fluent_cart_update_option() is a check-then-create: two admins saving a carrier
     * before its row exists can both find nothing and both INSERT, leaving duplicates
     * whose reads then resolve in whatever order the DB returns them. A unique index
     * cannot prevent it — fct_meta is shared, option rows carry a NULL object_id
     * (MySQL treats NULLs as distinct), and constraining `object_type + meta_key`
     * would reject object metadata, which legitimately repeats a key per object_id.
     *
     * So converge instead of locking: collapse onto the oldest row and re-assert our
     * value on it. Re-asserting is what makes a concurrent save safe — the writer who
     * runs second would otherwise find its own row already deleted by the first and
     * silently lose the save. Settings are last-write-wins, so the survivor is simply
     * whoever finished last.
     */
    protected function normalizeStoredRow(array $merged): void
    {
        $rows = Meta::query()
            ->where('meta_key', $this->metaKey)
            ->where('object_type', 'option')
            ->orderBy('id', 'ASC')
            ->get();

        $keep = $rows->first();

        if (!$keep) {
            return;
        }

        $mutated = false;

        if ($rows->count() > 1) {
            Meta::query()
                ->where('meta_key', $this->metaKey)
                ->where('object_type', 'option')
                ->where('id', '!=', $keep->id)
                ->delete();

            $mutated = true;
        }

        // Re-assert our value on the survivor. Not just tidiness: a racing writer may
        // have already collapsed onto a different row and deleted the one we wrote to,
        // and without this that writer's save would vanish without a trace.
        if ($keep->meta_value != $merged) {
            $keep->meta_value = $merged;
            $keep->save();

            $mutated = true;
        }

        if ($mutated) {
            // fluent_cart_get_option() memoizes per request and may be holding the row
            // we just deleted, or a value we have since overwritten.
            fluent_cart_get_option($this->metaKey, null, false);
        }
    }
}
