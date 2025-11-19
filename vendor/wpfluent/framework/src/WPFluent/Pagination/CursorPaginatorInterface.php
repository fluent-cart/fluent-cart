<?php

namespace FluentCart\Framework\Pagination;

interface CursorPaginatorInterface
{
    /**
     * Get the URL for a given cursor.
     *
     * @param  \FluentCart\Framework\Pagination\Cursor|null  $cursor
     * @return string
     */
    public function url($cursor);

    /**
     * Add a set of query string values to the paginator.
     *
     * @param  array|string|null  $key
     * @param  string|null  $value
     * @return $this
     */
    public function appends($key, $value = null);

    /**
     * Get / set the URL fragment to be appended to URLs.
     *
     * @param  string|null  $fragment
     * @return $this|string|null
     */
    public function fragment($fragment = null);

    /**
     * Get the URL for the previous page, or null.
     *
     * @return string|null
     */
    public function previousPageUrl();

    /**
     * The URL for the next page, or null.
     *
     * @return string|null
     */
    public function nextPageUrl();

    /**
     * Get all of the items being paginated.
     *
     * @return array
     */
    public function items();

    /**
     * Get the "cursor" of the previous set of items.
     *
     * @return \FluentCart\Framework\Pagination\Cursor|null
     */
    public function previousCursor();

    /**
     * Get the "cursor" of the next set of items.
     *
     * @return \FluentCart\Framework\Pagination\Cursor|null
     */
    public function nextCursor();

    /**
     * Determine how many items are being shown per page.
     *
     * @return int
     */
    public function perPage();

    /**
     * Get the current cursor being paginated.
     *
     * @return \FluentCart\Framework\Pagination\Cursor|null
     */
    public function cursor();

    /**
     * Determine if there are enough items to split into multiple pages.
     *
     * @return bool
     */
    public function hasPages();

    /**
     * Get the base path for paginator generated URLs.
     *
     * @return string|null
     */
    public function path();

    /**
     * Determine if the list of items is empty or not.
     *
     * @return bool
     */
    public function isEmpty();

    /**
     * Determine if the list of items is not empty.
     *
     * @return bool
     */
    public function isNotEmpty();
}
