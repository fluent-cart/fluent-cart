<?php

namespace FluentCart\Framework\Http\Request;

use ArrayAccess;
use SplFileInfo;
use JsonSerializable;
use RuntimeException;
use FluentCart\Framework\Support\Util;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\Validator\Contracts\File as Contract;

class File extends SplFileInfo implements Contract, JsonSerializable, ArrayAccess
{
    /**
     * Original file name.
     *
     * @var string $originalName
     */
    private $originalName;

    /**
     * Mime type of the file.
     *
     * @var string $mimeType
     */
    private $mimeType;

    /**
     * File size in bytes.
     *
     * @var int|null $size
     */
    private $size;

    /**
     * File upload error.
     *
     * @var int $error
     */
    private $error;

    /**
     * HTTP File instantiator.
     *
     * @param $path
     * @param $originalName
     * @param null $mimeType
     * @param null $size
     * @param null $error
     */
    public function __construct(
        $path,
        $originalName,
        $mimeType = null,
        $size = null,
        $error = null
    ) {
        $this->init($path, $size, $error);
        $this->originalName = $this->getName($originalName);
        $this->mimeType = $this->getFileMimeType($mimeType);
    }

    /**
     * Init the file object with size and error.
     * 
     * @param  string $path
     * @param  int|null $size
     * @param  string|null $error
     * @return void
     */
    protected function init($path, $size, $error)
    {
        $this->size = $size ?: @filesize($path);
        $this->error = $error ?: UPLOAD_ERR_OK;
        parent::__construct($path);
    }

    /**
     * @taken from \Symfony\Component\HttpFoundation\File\File
     *
     * Returns locale independent base name of the given path.
     *
     * @param string $name The new file name
     *
     * @return string containing
     */
    public function getName($name)
    {
        $originalName = str_replace('\\', '/', $name);
        
        $pos = strrpos($originalName, '/');
        
        $originalName = false === $pos ? $originalName : substr(
            $originalName, $pos + 1
        );

        return sanitize_file_name($originalName);
    }

    public function getFileMimeType($mimeType)
    {
        $mimeType = $mimeType ?: $this->getMimeType();

        if (!$mimeType) {
            $path = $this->getPathname() ?: $this->getRealPath();

            if (!file_exists($path)) {
                throw new RuntimeException(
                    "File does not exist at path: $path"
                );
            }

            if ($handle = @fopen($path, 'rb')) {
                $data = fread($handle, 8192);
                $finfo = new \finfo(FILEINFO_MIME_TYPE);
                $mimeType = $finfo->buffer($data);
                fclose($handle);
            } else {
                throw new RuntimeException(
                    "Failed to open file at path: $path"
                );
            }
        }

        return $mimeType ?: 'application/octet-stream';
    }

    /**
     * Get the file upload error.
     *
     * @return int
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * Returns whether the file was uploaded successfully.
     *
     * @return bool True if the file has been uploaded with HTTP and no error occurred
     */
    public function isValid()
    {
        $isOk = UPLOAD_ERR_OK === $this->getError();

        // Allow fake uploads in tests
        if (str_contains(App::make()->env(), 'test')) {
            return $isOk;
        }

        return $isOk && is_uploaded_file($this->getPathname());
    }

    /**
     * Returns the original file name.
     *
     * @return string The Name Of the file
     */
    public function getClientOriginalName()
    {
        return $this->originalName;
    }

    /**
     * Returns the original file extension.
     *
     * It is extracted from the original file name that was uploaded.
     * Then it should not be considered as a safe value.
     *
     * @return string The extension
     */
    public function getClientOriginalExtension()
    {
        return pathinfo($this->originalName, PATHINFO_EXTENSION);
    }

    /**
     * Take an educated guess of the file's extension.
     *
     * @return mixed|null
     */
    public function guessExtension()
    {
        return $this->getMimeTypeAndExtension()['ext'];
    }

    /**
     * Take an educated guess of the file's mime type.
     *
     * @return string
     */
    public function getMimeType()
    {
        return $this->getMimeTypeAndExtension()['type'];
    }

    /**
     * Take an educated guess of the file's mime type and ext
     * based on the WordsPress' get_allowed_mime_types.
     *
     * @return array
     * @see https://developer.wordpress.org/reference/functions/get_allowed_mime_types
     * @see https://developer.wordpress.org/reference/functions/wp_get_mime_types
     */
    public function getMimeTypeAndExtension()
    {
        $path = $this->getPathname();

        if(!function_exists('wp_check_filetype_and_ext')) {
            require_once ABSPATH .'wp-admin/includes/file.php';
        }

        return wp_check_filetype_and_ext($path, $this->originalName);
    }

    /**
     * Get the file name.
     * 
     * @return string
     */
    public function getSavedFileName()
    {
        if ($name = $this->originalName) {
            return $name;
        }

        return basename($this->getPathname());
    }

    /**
     * Get the url from path.
     * 
     * @return string
     */
    public function getUrl()
    {
        return $this->url($this->getPathname());
    }

    /**
     * Returns the contents of the file.
     *
     * @return string the contents of the file
     *
     * @throws RuntimeException
     */
    public function getContents()
    {
        $level = error_reporting(0);
        $content = file_get_contents($this->getPathname());
        error_reporting($level);
        if (false === $content) {
            $error = error_get_last();
            throw new RuntimeException($error['message']);
        }

        return $content;
    }

    /**
     * Move the file to a new location.
     *
     * @param string $directory Target Path
     * @param string $name Target file name (optional)
     * @return self
     * @throws RuntimeException
     */
    public function move($directory, $name = null)
    {
        $err = '';

        $target = $this->getTargetFile($directory, $name);

        set_error_handler(function ($_, $msg) use (&$err) { $err = $msg; });

        try {
            $renamed = rename($this->getPathname(), $target);
        } finally {
            restore_error_handler();
        }

        if (!$renamed) {
            throw new RuntimeException(
                sprintf(
                    'Could not move the file "%s" to "%s" (%s).',
                    $this->getPathname(), $target, strip_tags($err)
                )
            );
        }

        @chmod($target, 0666 & ~umask());

        return new static($target, basename($target));
    }

    /**
     * Save the uploaded file.
     * 
     * @param  string $path
     * @return self (File Object)
     * @throws RuntimeException
     */
    public function save($path = null)
    {
        $path = $this->resolveTargetPath($path);

        return $this->move($path);
    }

    /**
     * Save the uploaded file with a given name.
     * 
     * @param  string $name
     * @param  string $path
     * @return self (File Object)
     * @throws RuntimeException
     */
    public function saveAs($name, $path = null)
    {
        $path = $this->resolveTargetPath($path);

        return $this->move($path, $name);
    }

    /**
     * Check that the given path exists.
     * 
     * @param  string $path
     * @return string
     * @throws RuntimeException
     */
    protected function resolveTargetPath($path)
    {
        if ($this->isAbsolutePath($path)) {
            
            $pieces = array_values(
                array_filter(
                    explode(DIRECTORY_SEPARATOR, $path)
                )
            );

            // Sometimes developers can pass a relative directory like an
            // absolute directory so in that case, If the root is not a
            // real directory then make it relative and resolve it:
            // i.e: 'a/relative/path/looks/like/an/absolute/path'
            
            if (!is_dir(DIRECTORY_SEPARATOR.$pieces[0])) {
                return $this->resolveTargetPath(trim($path, DIRECTORY_SEPARATOR));
            }

            $path = dirname($this->getTargetFile($path));

            if (!is_dir($path)) {
                throw new RuntimeException("Invalid file upload path: {$path}");
            }

            return $path;
        }

        $config = App::make('config');

        $default = $config->get(
            'app.file_upload_path', function() use ($config) {
                $slug = $config->get('app.slug');
                $uploadDir = wp_upload_dir()['basedir'];
                $uploadDir .= DIRECTORY_SEPARATOR . $slug;
                return $uploadDir;
            }
        );
        
        $path = trim(
            ($default . DIRECTORY_SEPARATOR . $path), DIRECTORY_SEPARATOR
        );

        $baseDir = trim(wp_upload_dir()['basedir'], DIRECTORY_SEPARATOR);

        if (strpos($path, $baseDir) !== 0) {
            $path = $baseDir . DIRECTORY_SEPARATOR . $path;
        }

        if (is_file($path)) {
            throw new RuntimeException("Invalid file upload path: {$path}");
        }

        return DIRECTORY_SEPARATOR.$path;
    }

    /**
     * Check if given path is absolute.
     * 
     * @param  string $path
     * @return boolean
     */
    function isAbsolutePath($path)
    {
        if (!$path) return false;

        // For Unix-like systems
        if (DIRECTORY_SEPARATOR === '/') {
            return $path[0] === '/';
        }

        // For Windows
        if (DIRECTORY_SEPARATOR === '\\') {
            return preg_match(
                '/^[a-zA-Z]:\\\\/', $path
            ) || substr($path, 0, 2) === '\\\\';
        }

        return false;
    }

    /**
     * Get the URL from the file path.
     * 
     * @param  string $path
     * @return string
     */
    public function url($path = '')
    {
        return Util::pathToUrl($path ?: $this->getPathname());
    }

    /**
     * Get the target file name to move (full path).
     *
     * @param string $directory Target Path
     * @param string $name Target file name (optional)
     * @return string
     * @throws RuntimeException
     */
    protected function getTargetFile($directory, $name = null)
    {
        if (!is_dir($directory)) {
            if (false === @mkdir($directory, 0777, true) && !is_dir($directory)) {
                throw new RuntimeException(
                    sprintf('Unable to create the "%s" directory.', $directory)
                );
            }
        } elseif (!is_writable($directory)) {
            throw new RuntimeException(
                sprintf('Unable to write in the "%s" directory.', $directory)
            );
        }

        return $this->makeTargetPath($directory, $name);
    }

    /**
     * Resolves the absolute path for saving.
     * 
     * @param  string $dir
     * @param  string $name
     * @return string
     */
    protected function makeTargetPath($dir, $name)
    {
        return $dir . DIRECTORY_SEPARATOR . $this->resolveFileName($name);
    }

    /**
     * Resolves the file name for saving.
     * 
     * @param  string|null $name
     * @return string
     */
    protected function resolveFileName($name = null)
    {
        if ($name) {
            $name = $this->getName($name);
            if (!$this->hasExtension($name)) {
                $name .= '.' . $this->guessExtension();
            }
        } else {
            $name = $this->originalName;
        }

        return $name;
    }

    /**
     * Retrieve the extension of the uploaded file.
     * 
     * @return string
     */
    public function extension()
    {
        return $this->guessExtension();
    }

    /**
     * Get the temporary file path.
     *
     * @return string
     */
    public function path()
    {
        return $this->getPathname();
    }

    /**
     * Check if the given filename has an extension.
     * 
     * @param  string $filename
     * @return boolean
     */
    protected function hasExtension($filename)
    {
        $info = pathinfo($filename);
        return isset($info['extension']) && $info['extension'] !== '';
    }

    /**
     * Get original HTTP file array
     *
     * @return array
     */
    public function toArray()
    {
        return [
            'type'          => $this->mimeType,
            'size_in_bytes' => $this->size,
            'size'          => size_format($this->size),
            'name'          => $this->getSavedFileName(),
            'path'          => $this->getPathname(),
            'url'           => $this->getUrl(),
            'tmp_name'      => $this->getPathname(),
            'error'         => $this->getError(),
        ];
    }

    /**
     * JsonSerialize implementation
     * @return array
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
    {
        return $this->toArray();
    }

    /* ArrayAccess methods */
    
    /**
     * Check if the property exists.
     * @param string $offset
     * @return bool
     */
    #[\ReturnTypeWillChange]
    public function offsetExists($offset)
    {
        return array_key_exists($offset, $this->toArray());
    }

    /**
     * Get the property.
     * 
     * @param  string $offset
     * @return string        
     */
    #[\ReturnTypeWillChange]
    public function offsetGet($offset)
    {
        $array = $this->toArray();
        
        return $array[$offset] ?? null;
    }

    #[\ReturnTypeWillChange]
    public function offsetSet($offset, $value)
    {
        //...
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($offset)
    {
        //...
    }
}
