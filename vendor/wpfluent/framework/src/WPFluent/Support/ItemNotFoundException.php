<?php

namespace FluentCart\Framework\Support;

use RuntimeException;

class ItemNotFoundException extends RuntimeException
{
	protected $message = 'No item was found in the collection.';
}
