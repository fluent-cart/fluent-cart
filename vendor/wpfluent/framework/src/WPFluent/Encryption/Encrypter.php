<?php

namespace FluentCart\Framework\Encryption;

use RuntimeException;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Encryption\EncryptException;
use FluentCart\Framework\Encryption\DecryptException;

class Encrypter
{
    /**
     * The encryption key.
     *
     * @var string
     */
    protected $key;

    /**
     * The algorithm used for encryption.
     *
     * @var string
     */
    protected $cipher;

    /**
     * The plugin slug key.
     *
     * @var string
     */
    protected $slug;

    /**
     * The supported cipher algorithms and their properties.
     *
     * @var array
     */
    private static $supportedCiphers = [
        'aes-128-cbc' => ['size' => 16, 'aead' => false],
        'aes-256-cbc' => ['size' => 32, 'aead' => false],
        'aes-128-gcm' => ['size' => 16, 'aead' => true],
        'aes-256-gcm' => ['size' => 32, 'aead' => true],
    ];

    /**
     * Create a new encrypter instance.
     *
     * @param  string  $key
     * @param  string  $cipher
     * @return void
     *
     * @throws \RuntimeException
     */
    public function __construct($key = null, $cipher = 'aes-128-cbc')
    {
    	$this->cipher = $cipher;

        $this->slug = $this->getSlug();
        
        $key = $key ?: $this->getKey();

        if (!static::supported($key, $this->cipher)) {
            $ciphers = implode(', ', array_keys(self::$supportedCiphers));

            throw new RuntimeException("Unsupported cipher or incorrect key length. Supported ciphers are: {$ciphers}.");
        }

        $this->key = $key;
    }

    /**
     * Determine if the given key and cipher combination is valid.
     *
     * @param  string  $key
     * @param  string  $cipher
     * @return bool
     */
    public static function supported($key, $cipher)
    {
        if (!isset(self::$supportedCiphers[strtolower($cipher)])) {
            return false;
        }

        return mb_strlen(
        	$key, '8bit'
        ) === self::$supportedCiphers[strtolower($cipher)]['size'];
    }

    /**
     * Create a new encryption key for the given cipher.
     *
     * @param  string  $cipher
     * @return string
     */
    public static function generateKey($cipher)
    {
        return random_bytes(
        	self::$supportedCiphers[strtolower($cipher)]['size'] ?? 32
        );
    }

    /**
     * Encrypt the given value.
     *
     * @param  mixed  $value
     * @param  bool  $serialize
     * @return string
     *
     * @throws \FluentCart\Framework\Encryption\EncryptException
     */
    public function encrypt($value, $serialize = true)
    {
        $iv = random_bytes(openssl_cipher_iv_length(strtolower($this->cipher)));

        $value = \openssl_encrypt(
            $serialize ? serialize($value) : $value,
            strtolower($this->cipher), $this->key, 0, $iv, $tag
        );

        if ($value === false) {
            throw new EncryptException('Could not encrypt the data.');
        }

        $iv = base64_encode($iv);
        $tag = base64_encode($tag ?? '');

        $mac = self::$supportedCiphers[strtolower($this->cipher)]['aead']
            ? '' // For AEAD-algorithms, the tag / MAC is returned by openssl_encrypt...
            : $this->hash($iv, $value, $this->key);

        $json = json_encode(compact('iv', 'value', 'mac', 'tag'), JSON_UNESCAPED_SLASHES);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new EncryptException('Could not encrypt the data.');
        }

        return base64_encode($json);
    }

    /**
     * Encrypt a string without serialization.
     *
     * @param  string  $value
     * @return string
     *
     * @throws \FluentCart\Framework\Encryption\EncryptException
     */
    public function encryptString($value)
    {
        return $this->encrypt($value, false);
    }

    /**
     * Decrypt the given value.
     *
     * @param  string  $payload
     * @param  bool  $unserialize
     * @return mixed
     *
     * @throws \FluentCart\Framework\Encryption\DecryptException
     */
    public function decrypt($payload, $unserialize = true)
    {
        $payload = $this->getJsonPayload($payload);

        $iv = base64_decode($payload['iv']);

        $this->ensureTagIsValid(
            $tag = empty($payload['tag']) ? null : base64_decode($payload['tag'])
        );

        $foundValidMac = false;

        // Here we will decrypt the value. If we are able to successfully decrypt it
        // we will then unserialize it and return it out to the caller. If we are
        // unable to decrypt this value we will throw out an exception message.
        foreach ($this->getAllKeys() as $key) {
            if (
                $this->shouldValidateMac() &&
                !($foundValidMac = $foundValidMac || $this->validMacForKey($payload, $key))
            ) {
                continue;
            }

            $decrypted = \openssl_decrypt(
                $payload['value'], strtolower($this->cipher), $key, 0, $iv, $tag ?? ''
            );

            if ($decrypted !== false) {
                break;
            }
        }

        if ($this->shouldValidateMac() && !$foundValidMac) {
            throw new DecryptException('The MAC is invalid.');
        }

        if (($decrypted ?? false) === false) {
            throw new DecryptException('Could not decrypt the data.');
        }

        return $unserialize ? unserialize($decrypted) : $decrypted;
    }

    /**
     * Decrypt the given string without unserialization.
     *
     * @param  string  $payload
     * @return string
     *
     * @throws \FluentCart\Framework\Encryption\DecryptException
     */
    public function decryptString($payload)
    {
        return $this->decrypt($payload, false);
    }

    /**
     * Create a MAC for the given value.
     *
     * @param  string  $iv
     * @param  mixed  $value
     * @param  string  $key
     * @return string
     */
    protected function hash($iv, $value, $key)
    {
        return hash_hmac('sha256', $iv.$value, $key);
    }

    /**
     * Get the JSON array from the given payload.
     *
     * @param  string  $payload
     * @return array
     *
     * @throws \FluentCart\Framework\Encryption\DecryptException
     */
    protected function getJsonPayload($payload)
    {
        if (!is_string($payload)) {
            throw new DecryptException('The payload is invalid.');
        }

        $payload = json_decode(base64_decode($payload), true);

        // If the payload is not valid JSON or does not have the proper keys set we will
        // assume it is invalid and bail out of the routine since we will not be able
        // to decrypt the given value. We'll also check the MAC for this encryption.
        if (!$this->validPayload($payload)) {
            throw new DecryptException('The payload is invalid.');
        }

        return $payload;
    }

    /**
     * Verify that the encryption payload is valid.
     *
     * @param  mixed  $payload
     * @return bool
     */
    protected function validPayload($payload)
    {
        if (!is_array($payload)) {
            return false;
        }

        foreach (['iv', 'value', 'mac'] as $item) {
            if (!isset($payload[$item]) || !is_string($payload[$item])) {
                return false;
            }
        }

        if (isset($payload['tag']) && !is_string($payload['tag'])) {
            return false;
        }

        return strlen(base64_decode($payload['iv'], true)) === openssl_cipher_iv_length(strtolower($this->cipher));
    }

    /**
     * Determine if the MAC for the given payload is valid for the primary key.
     *
     * @param  array  $payload
     * @return bool
     */
    protected function validMac(array $payload)
    {
        return $this->validMacForKey($payload, $this->key);
    }

    /**
     * Determine if the MAC is valid for the given payload and key.
     *
     * @param  array  $payload
     * @param  string  $key
     * @return bool
     */
    protected function validMacForKey($payload, $key)
    {
        return hash_equals(
            $this->hash($payload['iv'], $payload['value'], $key), $payload['mac']
        );
    }

    /**
     * Ensure the given tag is a valid tag given the selected cipher.
     *
     * @param  string  $tag
     * @return void
     */
    protected function ensureTagIsValid($tag)
    {
        if (self::$supportedCiphers[strtolower($this->cipher)]['aead'] && strlen($tag) !== 16) {
            throw new DecryptException('Could not decrypt the data.');
        }

        if (!self::$supportedCiphers[strtolower($this->cipher)]['aead'] && is_string($tag)) {
            throw new DecryptException('Unable to use tag because the cipher algorithm does not support AEAD.');
        }
    }

    /**
     * Determine if we should validate the MAC while decrypting.
     *
     * @return bool
     */
    protected function shouldValidateMac()
    {
        return !self::$supportedCiphers[strtolower($this->cipher)]['aead'];
    }

    /**
     * Get the application encryption key.
     * 
     * Developers may override the database key name using the filter:
     * `{slug}.encryption.option_key`
     *
     * @return string
     */
    public function getSlug()
    {
        $slug = App::config()->get('app.slug');

        $default = $slug . '_enc_key';

        /**
         * Allow developer to override the encryption key option name.
         *
         * @param string $default Default option key name.
         */
        return App::applyFilters($slug . '.encryption.option_key', $default);
    }

    /**
     * Get the encryption key that the encrypter is currently using.
     *
     * @return string
     */
    public function getKey()
    {
		if (!$key = get_option($this->slug)) {

			add_option($this->slug, base64_encode(
				$this->generateKey($this->cipher)
			));

			$key = get_option($this->slug);
		}

        return base64_decode($key);
    }

    /**
     * Get the current encryption key and all previous encryption keys.
     *
     * This is useful for key rotation, allowing decryption of data
     * encrypted with older keys.
     *
     * @return array Array of binary encryption keys.
     */
    public function getAllKeys()
    {
        $keys = [$this->key];

        // Get the old keys option name, allowing override via filter
        $oldKeysOption = App::applyFilters(
            $this->slug . '.encryption.old_keys_option',
            $this->slug . '_old_enc_key'
        );

        $oldKeys = get_option($oldKeysOption, []);

        foreach ($oldKeys as $encodedKey) {
            $decoded = base64_decode($encodedKey, true);
            if ($decoded !== false) {
                $keys[] = $decoded;
            }
        }
        
        return $keys;
    }

    /**
     * Rotate the encryption key.
     *
     * This method archives the current key in the old keys list,
     * generates a new encryption key, stores it in the database,
     * and updates the encrypter instance with the new key.
     *
     * @return void
     */
    public function rotateKey()
    {
        $currentEncoded = base64_encode($this->key);

        // Get the old keys option name, allowing override via filter
        $oldKeysOption = App::applyFilters(
            $this->slug . '.encryption.old_keys_option',
            $this->slug . '_old_enc_key'
        );

        $oldKeys = get_option($oldKeysOption, []);

        // Add current key to old list if not already present
        if (!in_array($currentEncoded, $oldKeys, true)) {
            $oldKeys[] = $currentEncoded;
            update_option($oldKeysOption, $oldKeys);
        }

        // Generate and store new key
        $newKey = base64_encode(static::generateKey($this->cipher));
        update_option($this->slug, $newKey);

        // Update instance property with decoded new key
        $this->key = base64_decode($newKey);
    }
}
