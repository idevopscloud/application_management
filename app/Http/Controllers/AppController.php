<?php

namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;

use Illuminate\Http\Request;
use App\Http\Requests;
use Illuminate\Support\Facades\Response;
use App\App;
use Illuminate\Support\Facades\Input;
use App\AppNode;
use Illuminate\Support\Facades\DB;
use App\Providers\Api\ApiProvider;
use Illuminate\Support\Facades\Event;
use App\Events\AppCreateEvent;
use App\AppNodeGroup;
use App\Providers\Application\AppProvider;
use Illuminate\Support\Facades\Validator;
use App\PaasDeployEnv;
use App\Providers\Env\EnvProvider;
use App\Exceptions\ValidateException;

class AppController extends BaseController
{
    public function index(Request $request) {
    	$apps = [];
    	$company = [];
    	$group_ids = [];
    	$action = Input::get('action');
    	$token = Input::get('token');
    	$appBuilder = App::with([/*'nodes', */'nodeGroups'=>function($query) {
    		$query->with('cluster');
    	}]);
    	$user = with(new ApiProvider)->getUserInfo(['token'=>$token]);
    	if ($action == 'mime') {
    		$company = with(new ApiProvider)->getCompany(['token'=>$token, 'id'=>$user['company_id']]);
	    	$groups = with(new ApiProvider)->getUserRoleGroups(['token'=>$token]);
	    	if ($groups) {
	    		foreach ($groups as $group) {
	    			$group_ids[] = $group['id'];
	    		}
	    		$appBuilder->whereIn('role_group_id', $group_ids);
	    	} else {
	    		return Response::Json(['apps'=>[], 'company'=>$company]); 
	    	}
    	} else if ($action == 'team') {
    		$appBuilder->where('company_id', $request->input('company_id', null))->
    			with(['instances' => function ($query) {
    				$query->select('app_id', 'id', 'name');
					$query->with(['components'=> function($query){
						$query->select('app_instance_id', 'name');
					}]);
			}]);
    	} else if ($request->name) {
    		$appBuilder->where('name', $request->name);
    	} else {
    		$appBuilder->where('master_user_id', $user['id']);
    	}
    	
    	$apps = $appBuilder->get();
    	return Response::Json(['apps'=>$apps, 'company'=>$company]);
	}
	
	public function show($id) {
		$node_group_id = Input::get('node_group');
		$app_builder = App::with([
			'nodeGroups'=>function($query) {
	    		$query->with(['cluster', 'nodes', 'instances']);
	    	}, 
			'instances' => function ($query) use ($node_group_id) {
				if ($node_group_id)
					$query->where('node_group_id', $node_group_id);
			}
		]);

		$app = $app_builder->find($id);
		return Response::Json($app);
	}
	
	public function destroy($id, Request $request) {
		$app = App::with(['instances'])->findOrFail($id);
		
		// remove node label
		/*foreach ($app->nodes as $node) {
			$group = $node->group ()->first ();
			with(new AppProvider())->patchNodeLabel($node->isp, $node->ipaddress,[['op'=>'remove', 'path'=>"/labels/idevops.app.{$app->id}.{$group->name}"]]);
			$node->delete();
		}*/
		
		// remove role-group
		with(new ApiProvider)->deleteGroups(['token'=>$request->token, 'id'=>$app->role_group_id]);
		
		// delete registry's build history
		foreach ($app->instances as $instance) {
			$posts = with(new ApiProvider)->getRegistryPosts(['token'=>$request->token, 'action'=>'search', 'namespace'=>$instance->name, 'type'=>'comp_img']);
			if (count($posts['data']) > 0) {
				foreach ($posts['data'] as $post) {
					with(new ApiProvider)->deleteRegistryPosts(['token'=>$request->token, 'id'=>$post['id']]);
				}
			}
		}
		
		// delete app instances
		foreach ($app->instances as $instance) {
			with(new AppProvider())->destroyInstance($instance, $request);
		}
		
		// finally delete app
		if ($app->forceDelete())
			return Response::Json([]);
	}
	
	public function store(Request $request) {
		$apiProvider = new ApiProvider;
		$validate_rules = [
			'name' => 'required|unique:apps,name',
			'code_name' => 'unique:apps,code_name',
			'description' => 'required',
			'app_icon_path' => 'required',
			'ngs' => 'required|array'
		];

		$user = $apiProvider->getUserInfo(['token'=>Input::get('token')]);
		/*$env = PaasDeployEnv::where('company_id', $user['company_id'])->first();
		if ($env) {
			// 私有资源池，开发节点组必须设置
			$develop_required = false;
			if (!empty($request->nodes)) {
				foreach ($request->nodes as $location=>$enviroments) {
					if (array_key_exists('develop', $enviroments)) {
						$develop_required = true;
					}
				}
			}
			if (!$develop_required) {
				throw new \Exception(trans("exception.develop_is_required"));
			}
			
			$validate_rules['nodes'] = 'required';
			$nodes = $request->nodes;
			$node_data = with(new EnvProvider())->getNodes($env);
		} else {
			$env = PaasDeployEnv::where('company_id', null)->firstOrFail();
			$node_data = with(new EnvProvider())->getNodes($env);
			$nodes = [];
			if (!empty($node_data['items'])) {
				foreach ($node_data['items'] as $item) {
					$public_ipaddress = isset($item['public_address']) ? $item['public_address'] :$item['IP'];
					$nodes[$env['location']]['develop'][] = ['private_ip'=>$item['IP'], 'public_ip'=>$public_ipaddress];
					$nodes[$env['location']]['product'][] = ['private_ip'=>$item['IP'], 'public_ip'=>$public_ipaddress];
				}
			}
		}*/
		$validator = Validator::make($request->all(), $validate_rules);
		
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}
		
		$appData = [
			'id' => gen_uuid(),
			'name' => trim(Input::get('name')),
			'code_name' => Input::get('name'),
			'description' => Input::get('description'),
			'master_user_id' => $user['id'],
			'master_user_name' => $user['name'],
			'company_id' => $user['company_id'],
			'icon' => Input::get('app_icon_path')
		];
		
		DB::beginTransaction();
		try {
			$app = new App($appData);
			$app->save();
			$app->nodeGroups()->withTimestamps()->attach($request->ngs);
		} catch (\Exception $e) {
			DB::rollBack();
			throw $e;
		} finally {
			Event::fire(new AppCreateEvent(Input::get('token'), Input::get('access_token'),$app->id, Input::get('users')));
		}
		DB::Commit();
		return Response::Json($app);
	}
	
	public function update($id, Request $request) {
		$validate_rules = [
				'code_name' => "unique:apps,code_name,{$id},id",
				'description' => 'required',
				'app_icon_path' => 'required'
		];
		
		$apiProvider = new ApiProvider;
		$app = App::with(['nodeGroups'])->findOrFail($id);
		
		$user = $apiProvider->getUserInfo(['token'=>Input::get('token')]);
		$user = $apiProvider->getUserInfo(['token'=>Input::get('token')]);
		/*$env = PaasDeployEnv::where('company_id', $user['company_id'])->first();
		if ($app->nodeGroups) {
			$develop_required = false;
			if (!empty($request->nodes)) {
				foreach ($request->nodes as $location=>$enviroments) {
					if (array_key_exists('develop', $enviroments)) {
						$develop_required = true;
					}
				}
			}
			if (!$develop_required) {
				throw new \Exception(trans("exception.develop_is_required"));
			}
			
			$validate_rules['nodes'] = 'required';
			$nodes = $request->nodes;
			$node_data = with(new EnvProvider())->getNodes($env);
		} else {
			$nodes = [];
		}*/
		
		$validator = Validator::make($request->all(), $validate_rules);
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}
		
		if ($app->master_user_id != $user['id']) {
			return Response::Json([]);
		}
		
		$appData = array(
				// 'name' => trim(Input::get('name')), // 不允许修改
				// 'code_name' => Input::get('name'),
				'description' => Input::get('description'),
				'icon' => Input::get('app_icon_path'),
				'company_id' =>$user['company_id']
		);
		
		DB::beginTransaction();
		try {
			$app->update($appData);
			/*if ($request->ngs) {
				$ngs = $app->nodeGroups()->lists('id', 'id')->toArray();
				$unbinds = array_diff(($ngs), ($request->ngs));
			}*/
			$app->nodeGroups()->detach();
			$app->nodeGroups()->withTimestamps()->attach($request->ngs);
		} catch (\Exception $e) {
			DB::rollBack();
			throw $e;
		} finally {
			Event::fire(new AppCreateEvent(Input::get('token'), Input::get('access_token'),$app->id, Input::get('users')));
			DB::Commit();
		}
		return Response::Json($app);
	}
	
}
