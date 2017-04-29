<?php namespace App\Exceptions;

class PaasException extends \Exception {
	
	protected $previous = 'Paas service exception';
	
}