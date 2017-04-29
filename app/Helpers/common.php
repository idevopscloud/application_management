<?php

use Illuminate\Support\Facades\Log;
use App\Exceptions\ApiException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Exceptions\PaasException;

/**
 * generator uuid
 * 
 * @return string
 */
function gen_uuid() {
	return sprintf( '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			// 32 bits for "time_low"
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),

			// 16 bits for "time_mid"
			mt_rand( 0, 0xffff ),

			// 16 bits for "time_hi_and_version",
			// four most significant bits holds version number 4
			mt_rand( 0, 0x0fff ) | 0x4000,

			// 16 bits, 8 bits for "clk_seq_hi_res",
			// 8 bits for "clk_seq_low",
			// two most significant bits holds zero and one for variant DCE1.1
			mt_rand( 0, 0x3fff ) | 0x8000,

			// 48 bits for "node"
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
	);
}

/**
 * request url
 * 
 * @param unknown $url
 * @param unknown $method
 * @param unknown $parameters
 * @param string $headers
 * @return multitype:mixed unknown
 */
function do_request($url, $method, $parameters = [],$headers = null) {
    $response = request_api($url, $method, $parameters, $headers);
    $content = json_decode ( $response ['content'], true );
    if ($response['code'] != 200) {
    	throw new HttpException($content ['message'], $response['code']);
    }
	if (isset($content['code']) && $content['code'] != 0) {
		throw new ApiException($content['msg'], $content['code']);
	}
	$data = isset($content['data']) ? $content['data'] : $content;
	return $data;
}

function do_request_paas($url, $method, $parameters = [],$headers = null, $api_key = null) {
	!$headers && $headers = [
			'Accept: application/json',
			'Content-Type: application/json',
	];
	$ID_T = gen_uuid();
	$headers[] = "ID_T: {$ID_T}";
	$headers[] = "access_token: ".base64_encode(bin2hex(encrypt($ID_T, $api_key)));
	
    $response = request_api($url, $method, $parameters, $headers);
    $content = json_decode ( $response ['content'], true );
    if ($response['code'] != 200) {
    	$error_msg = 'Paas-api error';
    	if (isset($content ['message']['message'])) {
    		$error_msg = $content['message']['message'];
    	} else if (isset($content['message'])) {
    		$error_msg = $content['message'];
    	}
    	throw new PaasException($error_msg, $response['code']);
    }
    
	if (isset ( $content ['code'] ) && $content ['code'] != 0 && $content ['code'] != 200) {
		throw new PaasException($content ['message'], $content['code']);
	}
	$data = isset($content['data']) ? $content['data'] : $content;
	return $data;
}

function request_api($url, $method, $parameters = [], $headers=null){

	if ($headers === null) {
		$headers = array(
			'Accept: application/json',
			'Content-Type: application/json',
		);
	}

	$data = json_encode($parameters);
	$handle = curl_init();
	switch($method) {
		case 'GET':
			if (!empty($parameters)) {
				if (strpos($url, '?') === false)
					$url = $url . '?' . http_build_query ( $parameters );
				else 
					$url .= '&'.http_build_query ( $parameters );
			}
			break;
		case 'POST':
			curl_setopt($handle, CURLOPT_POST, true);
			curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
			break;
		case 'PUT':
			curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'PUT');
			curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
			break;
		case 'PATCH':
			curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'PATCH');
			curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
			break;
		case 'DELETE':
			curl_setopt($handle, CURLOPT_CUSTOMREQUEST, 'DELETE');
			curl_setopt($handle, CURLOPT_POSTFIELDS, $data);
			break;
	}

	curl_setopt($handle, CURLOPT_URL, $url);
	curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($handle, CURLOPT_SSL_VERIFYHOST, false);
	curl_setopt($handle, CURLOPT_SSL_VERIFYPEER, false);
	curl_setopt($handle, CURLOPT_CONNECTTIMEOUT ,0);
	curl_setopt($handle, CURLOPT_FOLLOWLOCATION, true ); // 支持跳转
	curl_setopt ( $handle, CURLOPT_TIMEOUT, 120 );
	
	Log::info ( '[Request Third App] URL : ' . $url . ' Method: ' . $method, ['parameters'=>$parameters, 'headers'=>$headers]);
	$content = curl_exec($handle);
	$code = curl_getinfo($handle, CURLINFO_HTTP_CODE);
	$error = curl_error($handle);
	curl_close($handle);
    return ['code'=>$code, 'content'=>$content, 'error'=>$error];	
}

function arrayRecursiveDiff($aArray1, $aArray2) {
	$aReturn = array();

	foreach ($aArray1 as $mKey => $mValue) {
		if (array_key_exists($mKey, $aArray2)) {
			if (is_array($mValue)) {
				$aRecursiveDiff = arrayRecursiveDiff($mValue, $aArray2[$mKey]);
				if (count($aRecursiveDiff)) { $aReturn[$mKey] = $aRecursiveDiff; }
			} else {
				if ($mValue != $aArray2[$mKey]) {
					$aReturn[$mKey] = $mValue;
				}
			}
		} else {
			$aReturn[$mKey] = $mValue;
		}
	}
	return $aReturn;
}

if (! function_exists('simple_decrypt')) {
	/**
	 * Returns decrypted original string
	 */
	function simple_decrypt($encrypted_string, $encryption_key) {
		$iv_size = mcrypt_get_iv_size(MCRYPT_BLOWFISH, MCRYPT_MODE_ECB);
		$iv = mcrypt_create_iv($iv_size, MCRYPT_RAND);
		$decrypted_string = mcrypt_decrypt(MCRYPT_BLOWFISH, $encryption_key, $encrypted_string, MCRYPT_MODE_ECB, $iv);
		return $decrypted_string;
	}
}