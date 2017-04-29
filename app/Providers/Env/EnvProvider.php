<?php

namespace App\Providers\Env;

use App\PaasDeployEnv;

class EnvProvider {
	
	public function getNodes(PaasDeployEnv $env) {
		$content = [ ];
		if ($env->paas_api_url) {
			$content = do_request ( $env->paas_api_url . '/nodes', 'GET' );
		}
		return $content;
	}
	
	public function getMasters(PaasDeployEnv $env) {
		$content = [ ];
		if ($env->paas_api_url) {
			$content = do_request ( $env->paas_api_url . '/masters', 'GET' );
		}
		return $content;
	}
}
