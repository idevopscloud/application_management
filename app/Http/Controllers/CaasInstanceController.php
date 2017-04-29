<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests;
use Illuminate\Support\Facades\Response;
use App\Providers\Api\ApiProvider;
use App\Providers\Caas\InstanceProvider;
use App\PaasDeployEnv;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use App\Exceptions\PaasException;

class CaasInstanceController extends Controller
{
	public function index(Request $request) {
		$user = with(new ApiProvider())->getUserInfo(['token'=>$request->token]);
		$namespace = InstanceProvider::CAAS_PREFIX.$user['company_id'];
		$env = PaasDeployEnv::where ( 'company_id', null )->firstOrFail ();
		$url = "{$env->paas_api_url}/caas_apps/{$namespace}/instances";
		$result = do_request_paas ( $url, 'GET',[],null,$env->api_key );
		return Response::Json($result);
	}
	
    public function store(Request $request) {
    	$validate_rules = [
    			'name' => 'required',
    			'image' => 'required',
    			'version' => 'required',
    			'requests_memory' => 'required|min:1'
    	];
    	$validator = Validator::make($request->all(), $validate_rules);
    	 
    	if ($validator->fails()) {
    		throw new \Exception($validator->errors());
    	}
    	$user = with(new ApiProvider())->getUserInfo(['token'=>$request->token]);
    	
    	$env = PaasDeployEnv::where ( 'company_id', null )->firstOrFail ();
    	$provider = new InstanceProvider($user['company_id'],$env);
    	$provider->setAttributes($request->all());
  		$definition = $provider->getDefinition();
  		
  		DB::beginTransaction ();
  		try {
  			with(new ApiProvider)->updateCompany(['token'=>$request->token, 'id'=>$user['company_id'], 'action'=>'add', 'mem'=>$request->requests_memory]);
  			$url = "{$env->paas_api_url}/caas_apps/{$provider->getNamespace()}/instances";
  			$result = do_request_paas ( $url, 'POST', $definition,$env->api_key );
  			$provider->saveSvcBinding();
  		} catch (PaasException $e) {
  			with(new ApiProvider)->updateCompany(['token'=>$request->token, 'id'=>$user['company_id'], 'action'=>'add', 'mem'=> (-1 * $request->requests_memory)]);
  			DB::rollBack();
  			throw $e;
  		} catch (\Exception $e) {
  			DB::rollBack();
  			throw $e;
  		} 
  		DB::Commit();
  		return Response::Json([]);
	}
	
	public function destroy($id, Request $request) {
		$user = with(new ApiProvider())->getUserInfo(['token'=>$request->token]);
		$env = PaasDeployEnv::where ( 'company_id', null )->firstOrFail ();
		$namespace = InstanceProvider::CAAS_PREFIX.$user['company_id'];
		$url = "{$env->paas_api_url}/caas_apps/{$namespace}/instances/{$id}";
		$status = do_request_paas ( $url, 'GET', [], null, $env->api_key );
		if(isset($status['pods'][0]['mem_request'])) {
			$requests_memory = $status['pods'][0]['mem_request'];
			$requests_memory = -1 * ((int)$requests_memory / 128);
		} else {
			throw new PaasException("Could not get mem request from paas api", 404);
		}
		$public_ipaddress = null;
		if(isset($status['svc']['public_addresses'][0], $status['svc']['ports'][0]['port'])) {
			$public_ipaddress = $status['svc']['public_addresses'][0];
			$port = $status['svc']['ports'][0]['port'];
			$name = $status['name'];
		}
		DB::beginTransaction ();
		try {
			$url = "{$env->paas_api_url}/caas_apps/{$namespace}/instances/{$id}";
			$result = do_request_paas ( $url, 'DELETE', [], null, $env->api_key );
			with(new ApiProvider)->updateCompany(['token'=>$request->token, 'id'=>$user['company_id'], 'action'=>'add', 'mem'=>$requests_memory]);
			$public_ipaddress && InstanceProvider::removeSvcBinding($public_ipaddress, $port, $name);
		} catch (\Exception $e) {
			logger($e);
			DB::rollBack();
			throw $e;
		}
		DB::Commit();
		return Response::Json($result);
	}
		
}
