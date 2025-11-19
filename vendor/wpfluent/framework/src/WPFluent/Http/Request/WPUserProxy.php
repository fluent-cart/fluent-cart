<?php

namespace FluentCart\Framework\Http\Request;

use WP_User;
use BadMethodCallException;
use InvalidArgumentException;
use FluentCart\Framework\Support\Str;

/**
 * Class FluentCart\Framework\Framework\Http\Request\WPUserProxy
 * 
 * @method void __construct() Constructs the class
 * @method void init() Initializes the object
 * @method mixed getDataBy(string $field, mixed $value) Retrieves data by field
 * @method bool __isset(string $name) Checks if a property is set
 * @method mixed __get(string $name) Dynamically gets a property
 * @method void __set(string $name, mixed $value) Dynamically sets a property
 * @method void __unset(string $name) Unsets a property
 * @method bool exists() Checks existence of something
 * @method mixed get(string $key) Gets a value by key
 * @method bool hasProp(string $prop) Checks if a property exists
 * @method array getRoleCaps() Gets role capabilities
 * @method void addRole(string $role, string $displayName, array $caps) Adds a role
 * @method void removeRole(string $role) Removes a role
 * @method void setRole(string $role) Sets a role
 * @method void levelReduction() Reduces levels
 * @method void updateUserLevelFromCaps() Updates user level from capabilities
 * @method void addCap(string $cap) Adds a capability
 * @method void removeCap(string $cap) Removes a capability
 * @method void removeAllCaps() Removes all capabilities
 * @method bool hasCap(string $cap) Checks if the object has a capability
 * @method string translateLevelToCap(int $level) Translates a level to a capability
 * @method self forBlog(int $blogId) Switches context to a blog
 * @method self forSite(int $siteId) Switches context to a site
 * @method int getSiteId() Gets the site ID
 */
class WPUserProxy
{
    /**
     * User identifier: ID, email, or WP_User instance
     *
     * @var int|string|WP_User
     */
    protected $userIdentifier;

    /**
     * Method aliases to pass through to WP_User
     *
     * @var array
     */
    protected $passthrough = [
        'can' => 'has_cap',
    ];

    /**
     * Store the user identifier (ID, email, or WP_User instance)
     *
     * @param int|string|WP_User $wpUser
     */
    public function __construct($wpUser)
    {
        $this->userIdentifier = $wpUser;
    }

    /**
     * Resolve the latest WP_User instance
     *
     * @return WP_User
     * @throws InvalidArgumentException
     */
    protected function wpUser()
    {
        $wpUser = $this->userIdentifier;

        if (is_int($wpUser)) {
            $wpUser = get_user_by('id', $wpUser);
        } elseif (is_string($wpUser) && strpos($wpUser, '@') !== false) {
            $wpUser = get_user_by('email', $wpUser);
        }

        if (!$wpUser instanceof WP_User) {
            throw new InvalidArgumentException('Invalid user');
        }

        return $wpUser;
    }

    /**
     * Get the user ID
     *
     * @return int
     */
    public function id()
    {
        return $this->wpUser()->ID;
    }

    /**
     * Get the user email
     *
     * @return string
     */
    public function email()
    {
        return $this->wpUser()->user_email;
    }

    /**
     * Get the user login
     *
     * @return string
     */
    public function login()
    {
        return $this->wpUser()->user_login;
    }

    /**
     * Get the user nicename
     *
     * @return string
     */
    public function nicename()
    {
        return $this->wpUser()->user_nicename;
    }

    /**
     * Get the user status
     *
     * @return int
     */
    public function status()
    {
        return $this->wpUser()->user_status;
    }

    /**
     * Get the display name
     *
     * @return string
     */
    public function displayName()
    {
        return $this->wpUser()->display_name;
    }

    /**
     * Get user roles
     *
     * @return array
     */
    public function getRoles()
    {
        return $this->wpUser()->roles;
    }

    /**
     * Get user capabilities / permissions
     *
     * @return array
     */
    public function getPermissions()
    {
        $permissions = [];
        foreach ($this->wpUser()->get_role_caps() as $cap => $value) {
            if ($value) {
                $permissions[] = $cap;
            }
        }
        return $permissions;
    }

    /**
     * Get user meta (fresh from database)
     *
     * @param string|null $metaKey
     * @param mixed|null $default
     * @return mixed
     */
    public function getMeta($metaKey = null, $default = null)
    {
        $meta = [];

        foreach (get_user_meta($this->wpUser()->ID) as $key => $value) {
            if ($key === 'session_tokens') continue;
            
            $meta[$key] = maybe_unserialize($value[0]);
        }

        if ($metaKey === null) {
            return $meta;
        }

        return $meta[$metaKey] ?? $default;
    }

    /**
     * Set user meta
     *
     * @param string $key
     * @param mixed $value
     * @return bool
     */
    public function setMeta($key, $value)
    {
        if (is_array($value) || is_object($value)) {
            $value = maybe_serialize($value);
        }

        return update_user_meta($this->wpUser()->ID, $key, $value);
    }

    /**
     * Get the underlying WP_User instance
     *
     * @return WP_User
     */
    public function toBase()
    {
        return $this->wpUser();
    }

    /**
     * Check if the user is a super admin
     *
     * @return bool
     */
    public function isSuperAdmin()
    {
        return is_super_admin($this->wpUser()->ID);
    }

    /**
     * Check if the user is an administrator
     *
     * @return bool
     */
    public function isAdmin()
    {
        return $this->is('administrator');
    }

    /**
     * Check if the user has a specific role
     *
     * @param string $role
     * @return bool
     */
    public function is($role)
    {
        return in_array($role, $this->wpUser()->roles);
    }

    /**
     * Dynamically get a WP_User property
     *
     * @param string $key
     * @return mixed
     */
    public function __get($key)
    {
        return $this->wpUser()->{$key};
    }

    /**
     * Dynamically set a WP_User property
     *
     * @param string $key
     * @param mixed $value
     */
    public function __set($key, $value)
    {
        $this->wpUser()->{$key} = $value;
    }

    /**
     * Check if a WP_User property exists
     *
     * @param string $key
     * @return bool
     */
    public function __isset($key)
    {
        return isset($this->wpUser()->{$key});
    }

    /**
     * Unset a WP_User property
     *
     * @param string $key
     */
    public function __unset($key)
    {
        unset($this->wpUser()->{$key});
    }

    /**
     * Dynamically call WP_User methods with passthrough and snake_case support
     *
     * @param string $method
     * @param array $args
     * @return mixed
     * @throws BadMethodCallException
     */
    public function __call($method, $args)
    {
        $original = $method;

        if (array_key_exists($method, $this->passthrough)) {
            $method = $this->passthrough[$method];
        } elseif (method_exists($this->wpUser(), Str::snake($method))) {
            $method = Str::snake($method);
        }

        if (method_exists($this->wpUser(), $method)) {
            return call_user_func_array([$this->wpUser(), $method], $args);
        }

        throw new BadMethodCallException(
            "Call to undefined method " . static::class . "::{$original}()"
        );
    }
}
