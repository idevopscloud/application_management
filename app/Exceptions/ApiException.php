<?php namespace App\Exceptions;

class ApiException extends \Exception {
	
	protected $previous = 'Api request exception';
	
}