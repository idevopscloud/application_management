<?php namespace App\Providers\Api;

use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Exceptions\ApiException;

class ApiProvider {

	public function __construct() {
	}

	public function __call($method, $parameters)
	{
		$host = Config::get('api.idevops_host');
		$api = Config::get("api.items.$method");
		$data = $parameters[0];
		if (!$api) {
			throw new ApiException ( "Api $method not found" );
		}
		$url = trim ( $host, '/' ) . '/' . $api ['path'];
		if (isset ( $api ['type'] ) && $api ['type'] == 'REST' && isset($data['id'])) {
			$url .= '/' . $data['id'];
			unset($data['id']);
		}
		return do_request($url, $api['method'], $data);
	}
}
