<?php

namespace FluentCart\Framework\Support;

use InvalidArgumentException;
use FluentCart\Framework\Foundation\App;
use FluentCart\Framework\View\View;

class Mail
{
    protected array $to = [];
    protected array $cc = [];
    protected array $bcc = [];
    protected string $from = '';
	protected string $fromName = '';
    protected string $subject = '';
    protected string $body = '';
    protected array $headers = [];
    protected array $attachments = [];
    protected string $contentType = 'text/html';

    /**
     * Set one or more recipients.
     *
     * @param string|array $emails
     * @return $this
     */
	public function to($emails)
    {
        $this->to = (array) $emails;
        return $this;
    }

    /**
     * Set the email sender.
     * 
     * @param  string      $email
     * @param  string|null $name
     * @return $this
     */
    public function from($email, $name = null)
    {
        $this->from = $email;
        $this->fromName = $name ?: get_bloginfo('name');
        return $this;
    }

    /**
     * Set one or more CC recipients.
     *
     * @param string|array $emails
     * @return $this
     */
    public function cc($emails)
    {
        $this->cc = array_merge($this->cc, (array) $emails);
        return $this;
    }

    /**
     * Set one or more BCC recipients.
     *
     * @param string|array $emails
     * @return $this
     */
    public function bcc($emails)
    {
        $this->bcc = array_merge($this->bcc, (array) $emails);
        return $this;
    }

    /**
     * Set the email subject.
     *
     * @param string $subject
     * @return $this
     */
    public function subject($subject)
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * Set the email body (HTML or plain text).
     *
     * @param string $body
     * @return $this
     */
    public function body($body)
    {
        $this->body = $body;
        return $this;
    }

    /**
     * Set the body from a PHP template file with optional data.
     *
     * @param string $templatePath
     * @param array $data
     * @return $this
     */
    public function view($templatePath, $data = [])
    {
        $this->body = App::make(View::class)->make($templatePath, $data);

        $this->contentType('text/html');

        return $this;
    }

    /**
     * Add or override headers.
     *
     * @param array|string $headers
     * @param string|null $value
     * @return $this
     */
    public function withHeader($headers, $value = null)
    {
        if (is_array($headers)) {
            $this->headers = array_merge($this->headers, $headers);
        } else {
            $this->headers[$headers] = $value;
        }

        return $this;
    }

    /**
     * Set Content-Type header (default is text/html).
     *
     * @param string $contentType
     * @return $this
     */
    public function contentType($contentType)
    {
        $this->contentType = $contentType;
        
        return $this;
    }

    /**
     * Attach one or more files.
     *
     * @param string|array $paths
     * @return $this
     */
    public function attach($paths)
    {
        $paths = (array) $paths;
        foreach ($paths as $path) {
            if (!file_exists($path)) {
                throw new InvalidArgumentException(
                	"Attachment file not found: {$path}"
                );
            }
            $this->attachments[] = $path;
        }

        return $this;
    }

    /**
     * Prepare headers for wp_mail.
     *
     * @return array
     */
    protected function prepareHeaders()
	{
	    $headers = [];

	    // Add content-type header
	    $headers[] = "Content-Type: {$this->contentType}; charset=UTF-8";

	    if ($this->from) {
	        $fromName = $this->fromName ?: $this->from;
	        $headers[] = "From: {$fromName} <{$this->from}>";
	    } else {
	        $fromEmail = apply_filters('wp_mail_from', get_bloginfo('admin_email'));
	        $fromName  = apply_filters('wp_mail_from_name', get_bloginfo('name'));
	        $headers[] = "From: {$fromName} <{$fromEmail}>";
	    }

	    if (!empty($this->cc)) {
	        $headers[] = 'Cc: ' . implode(', ', $this->cc);
	    }

	    if (!empty($this->bcc)) {
	        $headers[] = 'Bcc: ' . implode(', ', $this->bcc);
	    }

	    foreach ($this->headers as $key => $value) {
	        if (is_int($key)) {
	            $headers[] = $value;
	        } else {
	            $headers[] = "{$key}: {$value}";
	        }
	    }

	    return $headers;
	}

    /**
     * Send the email immediately.
     *
     * @return bool
     */
    public function send()
    {
        if (empty($this->to)) {
            throw new InvalidArgumentException(
            	"At least one recipient (to) must be specified."
            );
        }

        $to = implode(', ', $this->to);
        
        $headers = $this->prepareHeaders();

        return wp_mail(
        	$to,
        	$this->subject,
        	$this->body,
        	$headers,
        	$this->attachments
        );
    }

    /**
     * Static helper to start a new mail instance.
     *
     * @return static
     */
    public static function make()
    {
        return new static();
    }
}
