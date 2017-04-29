<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\PaasDeployEnv;
use App\AppNodeGroupTeamRel;
use Illuminate\Support\Facades\Response;
use App\Providers\Env\EnvProvider;
use App\Providers\Api\ApiProvider;

class EnvController extends Controller
{
	/**
	 * 集群列表
	 * 
	 * @param Request $request
	 */
    public function index(Request $request) {
    	$clusters = [];
    	$user = null;
    	if (!$request->company_id) {
	    	$user = with(new ApiProvider())->getUserInfo(['token'=>$request->token]);
	    	$company_id = $user['company_id'];
    	} else {
    		$company_id = $request->company_id;
    	}
    	if ($user && $user['id'] == 1) {
    			$clusters = PaasDeployEnv::get()->toArray(); // get all env when user's role was admin
    	} else {
	    	$rels = AppNodeGroupTeamRel::with(['ngs'=> function($query){
						$query->with('nodes');
					}])->where(['team_id' => $company_id])->get();
			foreach ($rels as $key => $rel) {
				if ($rel->ngs) {
					// foreach ($rel->ngs as $key => $ng) {
					$clusters[$rel->ngs->cluster->id] = $rel->ngs->cluster;
					// }
				}
			}
		}
    	// $envs = PaasDeployEnv::where('company_id', $company_id)->get()->toArray(); // private resource clusters
    	// public resource clusters
    	// $free = false;
    	/*if (!$envs && !$request->company_id) {
    		if ($user['id'] == 1) {
    			$envs = PaasDeployEnv::get()->toArray(); // get all env when user's role was admin
    		} else {
	    		$free = true;
	    		$envs = PaasDeployEnv::where('company_id', null)->get()->toArray();
    		}
    	}*/
    	
    	// cluster对应的resigtry images list
    	if ($request->action && $request->acction == 'image') {
    		$cluster = array_pop($clusters);
    		$images = do_request_paas("{$cluster->paas_api_url}/base_img", 'GET', ['app_name'=>'']); 
    		return Response::Json($images);
    	}
    	return Response::Json(['envs'=>$clusters, 'clusters'=>$clusters, 'free'=>false]);
	}
	
	/**
	 * 集群信息
	 * 
	 * @param int $id
	 */
	public function show(Request $request, $id) {
		$data = [];
		$env = PaasDeployEnv::findOrFail($id);
		if ($request->action == 'monitor') {
			$masters = with(new EnvProvider())->getMasters($env);
			$data['masters'] = $masters;
		}
			
		$nodes = with(new EnvProvider())->getNodes($env);
		$data['nodes'] = $nodes;
		
		$data['env'] = $env;
		return Response::Json($data);
	}
	
	/**
	 * 创建私有集群 <br>
	 * request:企业ID,集群名称<br>
	 * paas_api_url,k8s_endpoint取env设置的默认值
	 * 
	 * @param Request $request
	 * @throws \Exception
	 */
	public function store(Request $request) {
		$validator = validator ( $request->all (), [
			'name' => 'required|unique:paas_deploy_env,location',
		] );
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}
		$paas_api_url = $request->paas_api_url ? : env('PAAS_API_URL');
		$k8s_endpoint = $request->k8s_endpoint ? : env('K8S_END_POINT');
		$registry = null;
		if ($request->public == 'on') {
			$registry = with(new ApiProvider())->createRegistry([
				'token'=>$request->token, 
				'host' => $request->registry_host,
				'name'=>$request->registry_name, 
				'paas_api_url'=>$paas_api_url,
				'auth_user' => $request->auth_user ? : "",
				'auth_pwd' => $request->auth_pwd ? : "",
			]);
		}
		
		$envData = [
			'name' => $request->name,
			'location' => $request->name, // unique
			'paas_api_url' => $paas_api_url,
			'k8s_endpoint' => $k8s_endpoint,
			'registry_id' => ($registry ? $registry['id'] : ''),
			'registry_name' => ($registry ? $registry['name'] : '')
		];
		$env = new PaasDeployEnv($envData);
		$env->save();
		return Response::Json([]);
	}
	
	/**
	 * delete cluster
	 * 
	 * @param integer $id
	 */
	public function destroy($id) {
		$env = PaasDeployEnv::find($id);
		if ($env) {
			$env->forceDelete();
		}
		return Response::Json([]);
	}
	
	public function getPlatform() {
		$data = do_request_paas(env('MONITOR_PLATFORM_ENDPOINT'), 'GET');
		return Response::Json($data);
	}
}
