<?php

namespace FluentCart\Framework\Database\Query;

use FluentCart\Framework\Database\BaseGrammar;

class Expression implements ExpressionInterface
{

	/**
	 * The value of the expression.
	 *
	 * @var mixed
	 */
	protected $value;

	/**
	 * Create a new raw query expression.
	 *
	 * @param  mixed  $value
	 * @return void
	 */
	public function __construct($value)
	{
		$this->value = $value;
	}

	/**
     * Get the value of the expression.
     *
     * @param  \FluentCart\Framework\Database\BaseGrammar $grammar
     * @return mixed
     */
	public function getValue(BaseGrammar $grammar)
	{
		return $this->value;
	}

	/**
	 * Convert the object to its string representation.
	 * 
	 * @return string [description]
	 */
	public function __toString()
	{
		return (string) $this->value;
	}
}
