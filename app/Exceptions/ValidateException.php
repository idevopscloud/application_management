<?php namespace App\Exceptions;

class ValidateException extends \Exception {
	
	public function __construct($message, $code = null, $previous =null) {
		$this->message=$message;
	}
	
}