<?php

namespace FluentCart\App\Services;

use FluentCart\Api\StoreSettings;
use FluentCart\Framework\Support\Arr;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Entry MetaDat
 * @since 1.0.0
 */
class WpMetaHelper
{
    protected $entry;
    protected $userId;
    protected $queryVars = null;
    protected $user;

    public function __construct($entry)
    {
        $this->entry = $entry;
        $this->userId = Arr::get($entry, 'user_id', null);
    }

    public function getWPValues($key)
    {
        switch ($key) {
            case 'user_id':
                return $this->userId;
            case 'user_first_name':
                $user = $this->getUser();
                if (!$user) {
                    return '';
                }
                return $user->user_firstname;
            case 'user_last_name':
                $user = $this->getUser();
                if (!$user) {
                    return '';
                }
                return $user->user_lastname;
            case 'user_display_name':
                $user = $this->getUser();
                if (!$user) {
                    return '';
                }
                return $user->display_name;
            case 'user_email':
                $user = $this->getUser();
                if (!$user) {
                    return '';
                }
                return $user->user_email;
            case 'user_url':
                $user = $this->getUser();
                if (!$user) {
                    return '';
                }
                return $user->user_url;
            case 'site_title':
                return get_bloginfo('name');
            case 'site_url':
                return get_bloginfo('url');
            case 'admin_email':
                return get_bloginfo('admin_email');
            case 'store_logo';
                return $this->getStoreLogo();
            default:
                return '';
                break;
        }
    }

    public function getuserMeta($key)
    {
        $meta = get_user_meta($this->userId, $key, true);
        if (is_array($meta)) {
            return implode(', ', $meta);
        }
        return $meta;
    }

    public function getOtherData($key)
    {
        if ($key == 'date') {
            $dateFormat = get_option('date_format');
            return current_time($dateFormat);
        }

        if ($key == 'time') {
            $dateFormat = get_option('time_format');
            return current_time($dateFormat);
        }
        if ($key == 'user_ip') {
            return $this->entry->ip_address;
        }

        return '';
    }

    protected function getUser()
    {
        if ($this->user) {
            return $this->user;
        }
        $this->user = get_user_by('ID', $this->userId);
        return $this->user;
    }

    public function getStoreLogo(): string
    {
        $storeSettings = new StoreSettings();
        $logoPath = $storeSettings->get('store_logo');
        if (isset($logoPath) && !empty($logoPath)) {
            $html = '<img src="' . Arr::get($logoPath, 'url') . '" alt="Store Logo" style="max-height: 70px;">';
        } else {
            $html = '';
        }

        return $html;
    }
}