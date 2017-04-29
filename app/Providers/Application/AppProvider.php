<?php namespace App\Providers\Application;

use App\Exceptions\AppInstanceException;
use App\AppInstanceComponent;
use App\PaasDeployEnv;
use App\AppInstance;
use App\SvcBinding;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class AppProvider {
	
	protected $model;
	
	public function setModel($model) {
		$this->model = $model;
		return $this;
	}
	
	function patchNodeLabel($cluster, $ipaddress, $data) {
		// $env = PaasDeployEnv::where('location', $location)->firstOrFail();
		$url = $cluster->paas_api_url .'/nodes/'.$ipaddress;
		$response = do_request_paas($url, 'PATCH', $data, [ 
				"Content-Type:application/json-patch+json" 
		],$cluster->api_key );
		return $response;
	}
	
	function destroyInstance(AppInstance $instance, Request $request) {
		DB::beginTransaction ();
		try {
			// $env = PaasDeployEnv::where ( 'location', $instance->node_group->isp )->firstOrFail ();
			SvcBinding::where ( 'instance_id', $instance->id )->forceDelete ();
			do_request_paas ( "{$env->paas_api_url}/applications/{$instance->name}", 'DELETE');
			AppInstanceProvider::recoverMemUsage ( $instance->id, $instance->app->company_id, $request->token );
		} catch (\Exception $e) {
			\Log::error($e);
			DB::rollBack();
		} finally { 
		    $instance->forceDelete ();
		}
		DB::Commit();
	}
	
}


