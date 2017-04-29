<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests;
use App\AppInstance;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Response;
use App\Providers\Api\ApiProvider;
use App\Providers\Application\AppInstanceComponentProvider;
use App\App;
use App\AppInstanceComponent;
use Illuminate\Support\Facades\DB;
use App\PaasDeployEnv;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\Providers\Application\AppInstanceProvider;
use App\AppDeploy;
use App\AppDeployComponent;
use Illuminate\Support\Facades\Validator;
use App\Exceptions\ValidateException;
use App\Providers\Application\AppDeployComponentProvider;
use App\Events\BuildComponentImageEvent;
use Illuminate\Support\Facades\Event;
use App\Events\ComponentImageBuilderEvent;

class AppInstanceComponentController extends Controller
{
	public function index(Request $request) {
		$coms = [];
		if ($request->action == 'deploy') {
			$q = addcslashes(addcslashes($request->q, "/"), "\\");
			$coms = AppDeployComponent::whereRaw ("`definition` like '%$q%'  ESCAPE '|' ")->with(['deploy'=>function($query) use ($request){
				$query->with('instance')->where(['status' => $request->d_status, 'is_deploy'=>$request->d_is_deploy]);
			}])->get();
		}
		return Response::Json($coms);
	}
	/**
	 * create deploy record and component
	 */
	public function store(Request $request) {
		$container = Input::get('container');
		$config = Input::get('config');
		$depends = Input::get('depends', []);
		$hook = Input::get('hook');
		$monitor = Input::get('monitor');
		$log = Input::get('log');
		
		$app_id = Input::get('app_id');
		$instance_id = Input::get('instance_id');
		$deploy_id = Input::get('deploy_id');
		
	    $rules = [
                'instance_id' => 'required',
			];
		if ($deploy_id) { 
            $rules['name'] = "required|unique:app_deploy_components,name,NULL,id,app_deploy_id,{$deploy_id}";
		} else {
			$rules ['name'] = "required|unique:app_instance_components,name,NULL,id,app_instance_id,{$instance_id}";
        }
        
        // 不使用base-image启动组件，则需要从git及指定的base-image构建新的组件image
        $build = [];
        if (!$request->use_base_image) {
        	$rules['git.addr'] = "required";
        	$rules['git.tag'] = "required";
        	$rules['base_image'] = "required";
        	$rules['start_path'] = "required";
        	
        	$build = [
        		'base_image'=> $request->input('base_image'),
        		'git' => $request->git,
				'start_path' => $request->input ( 'start_path' ),
				'build_path' => $request->input ( 'build_path' )
			];
		}
        
        $validator = Validator::make($container + ['instance_id'=>$instance_id] + $build, $rules);
        if ($validator->fails()) {
            throw new ValidateException($validator->errors());
        }
        $user = with(new ApiProvider())->getUserInfo(['token'=>Input::get ( 'token' ) ] );
        $appInstance = AppInstance::with(['node_group', 'app'])->findOrFail($instance_id);
        
        // event: build component image
        if (!$request->use_base_image) {
        	
        	$registry = with(new ApiProvider())->getRegistry(['token'=>Input::get ( 'token' ), 'name'=>'platform'] );
        	if (!$registry) {
        		throw new \Exception(trans("exception.miss_registry"));
        	}
        	Event::fire(
        		new ComponentImageBuilderEvent([
        			'token' => $request->token,
        			'registry_id' => $registry['id'],
        			'namespace' => $appInstance->app->name,
					'image_name' => $container['name'],
					'image_tag' => $request->git['tag'],
					'git' => $request->git,
					'base_image' => $request->input ( 'base_image' ),
					'build_path' => $request->input ( 'build_path'),
					'start_path' => $request->start_path,
			]));
        	$container['version'] = $request->git['tag'];
        	$container['image'] = "{$registry['host']}/app/{$appInstance->app->name}/{$container ['name']}";
        	
        }
		if (!$deploy_id) { // 生成新的deploy记录（从instance,instance_component拷贝过来），并做相应的修改
			$instancePorvider = new AppInstanceProvider;
			$deployData = [
					'app_instance_id'=>$appInstance->id,
					'user_id' => $user['id'],
					'user_name' => $user ['name']
			];
			
			$deploy = $instancePorvider->newDeploy($instance_id, $deployData);
			
		}else {
			$deploy = AppDeploy::findOrFail ( $deploy_id );
		}
		/*$env = PaasDeployEnv::where('company_id', $user['company_id'])->where ( 'location', $appInstance->node_group->isp )->first();
		if (!$env) {
			$env = PaasDeployEnv::where('company_id', null)->where ( 'location', $appInstance->node_group->isp )->firstOrFail ();
		}*/
		if (empty($config['service']['port'][0])) {
			unset($config['service']['port']);
		}

		if (!empty($config['service']['port'])) {
			$nodes = $appInstance->node_group->nodes()->get()->toArray();
			$key = array_rand($nodes, 1);
			$config['service']['nodes'] = [$nodes[$key]['ipaddress']];
		}
		$componentProvider = new AppInstanceComponentProvider;
		$componentProvider->setSection('container', $container);
		$componentProvider->setSection('config', $config);
		$componentProvider->setSection('depends', $depends);
		$componentProvider->setSection('hook', $hook);
		$componentProvider->setSection('monitor', $monitor);
		$componentProvider->setSection('log', $log);
		
		$build && $componentProvider->setSection('build', $build);
		
		$componentProvider->setKubernetesEndpoint($appInstance->node_group->cluster->k8s_endpoint);
		$componentProvider->setNameSpace($appInstance->name);
		// $componentProvider->setAppId($app_id);
		$componentProvider->setGroup($appInstance->node_group->id);
		$componentProvider->genInternalId();
		$componentProvider->setDeployId($deploy->id);
		$definition = $componentProvider->getDefinition();
		
		$componentData = [
				'app_deploy_id' => $deploy->id,
				'name' => $container['name'],
				'version' => $container['version'],
				'definition' => json_encode($definition)
		];
		DB::beginTransaction();
		try {
			$component = new AppDeployComponent($componentData);
			$component->save();
			AppInstanceProvider::checkDuplicateBinding($deploy->components, $component);
		} catch (\Exception $e) {
			DB::rollBack();
			throw $e;
		}
		DB::Commit();
		return Response::Json(['deploy'=>$deploy, 'definition'=>$definition]);
	}
	
	/**
	 * update component and deploy record,create it if not exist
	 * 
	 * @param unknown $id
	 */
	public function update($id, Request $request) {
		$deploy = [];
		
		$container = Input::get('container');
		$config = Input::get('config');
		$depends = Input::get('depends', []);
		$hook = Input::get('hook');
		$monitor = Input::get('monitor');
		$log = Input::get('log');
		$deploy_id = Input::get('deploy_id');
		
		if (!$deploy_id) {
			$component = AppInstanceComponent::findOrFail($id);
			$instance_id = $component->app_instance_id;
            $rules['name'] = "required|unique:app_instance_components,name,{$id},id,app_instance_id,{$instance_id}";
		} else {
            $rules['name'] = "required|unique:app_deploy_components,name,{$id},id,app_deploy_id,{$deploy_id}";
			$component = AppDeployComponent::findOrFail($id);
			$deploy = AppDeploy::findOrFail($deploy_id);
			$instance_id = $deploy->app_instance_id;
		}
		
		// 不使用base-image启动组件，则需要从git及指定的base-image构建新的组件image
		$build = [];
		if (!$request->use_base_image) {
			$rules['git.addr'] = "required";
			$rules['git.tag'] = "required";
			$rules['base_image'] = "required";
			$rules['start_path'] = "required";
			 
			$build = [
				'base_image'=> $request->input('base_image'),
				'git' => $request->git,
				'start_path' => $request->input ( 'start_path' ),
				'build_path' => $request->input ( 'build_path' )
			];
		}
        
        $validator = Validator::make($container+$build, $rules);
        if ($validator->fails()) {
            throw new ValidateException($validator->errors());
        }
        $user = with(new ApiProvider())->getUserInfo(['token'=>Input::get ( 'token' ) ] );
        $appInstance = AppInstance::with('node_group')->findOrFail($instance_id);
        
        $componentProvider = new AppInstanceComponentProvider;
        $diff = [];
        $sectionData = $componentProvider->getSections($component);
        foreach($sectionData as $section=>$data) {
        	if (isset($request->$section) && is_array($request->$section)) {
        		$sectionDiff = arrayRecursiveDiff($request->$section, $data);
        		count($sectionDiff) > 0 && $diff[$section] = $sectionDiff;
        	}
        }
        if (empty($config['service']['port'][0])) {
        	unset($config['service']['port']);
        }
        
        /**
         * event: build component image
         * different version or image will be rebuild
         */ 
        if (!$request->use_base_image) {
        	$registry = with(new ApiProvider())->getRegistry(['token'=>Input::get ( 'token' ), 'name'=>'platform'] );
        	if (!$registry) {
        		throw new \Exception(trans("exception.miss_registry"));
        	}
        	if ($sectionData['container']['version'] != $request->git['tag'] or 
        			(isset($sectionData['build']['build_path']) && $sectionData['build']['build_path'] != $request->input('build_path')) or
        			(isset($sectionData['build']['start_path']) && $sectionData['build']['start_path'] != $request->input('start_path')) or
        			(isset($sectionData['build']['base_image']) && $sectionData['build']['base_image'] != $request->input('base_image'))
				/*$sectionData['container']['image'] != "{$registry['host']}/app/{$appInstance->app->name}/{$container ['name']}"*/
			) {
				$force_build = 0;
				if ((isset($sectionData['build']['build_path']) && $sectionData['build']['build_path'] != $request->input('build_path')) or
        			(isset($sectionData['build']['start_path']) && $sectionData['build']['start_path'] != $request->input('start_path')) or
        			(isset($sectionData['build']['base_image']) && $sectionData['build']['base_image'] != $request->input('base_image')))
					$force_build = 1;
	        	Event::fire(
		        	new ComponentImageBuilderEvent([
			        	'token' => $request->token,
			        	'registry_id' => $registry['id'],
			        	'namespace' => $appInstance->app->name,
			        	'image_name' => $container['name'],
			        	'image_tag' => $request->git['tag'],
			        	'git' => $request->git,
			        	'base_image' => $request->input ( 'base_image' ),
			        	'build_path' => $request->input('build_path'),
			        	'start_path' => $request->start_path,
			        	'force_build' => $force_build,
	        	]));
	        	
	        	$container['version'] = $request->git['tag'];
	        	$container['image'] = "{$registry['host']}/app/{$appInstance->app->name}/{$container ['name']}";
	        	$componentProvider->genInternalId();
			}
        }
        
        // $env = PaasDeployEnv::where('location', $appInstance->node_group->isp)->where('company_id', $user['company_id'])->first();
        $env = $appInstance->node_group->cluster;
        
        /**
         * 已有设置则保持分配的不变
         */
        if (!$env && isset($sectionData['config']['service']['nodes'], $sectionData['config']['service']['port'])) {
	        $config['service']['nodes'] = $sectionData['config']['service']['nodes'];
	        $config['service']['port'] = $sectionData['config']['service']['port'];
        }
        /**
         * 更新组件，增加svc设置则需要随机分配节点
         */
        if (!$env && !empty($config['service']['port']) && empty($config['service']['nodes'])) {
        	$nodes = $appInstance->node_group->nodes()->get()->toArray();
        	$key = array_rand($nodes, 1);
        	$config['service']['nodes'] = [$nodes[$key]['ipaddress']];
        }
        
        /**
         * 如果只设置了svc,InternalId将不改变
         */
        if (count($diff) > 1 || (count($diff) == 1 && !isset($diff['config']['service']))) {
        	$componentProvider->genInternalId();
        }
        
		
		if (!$deploy_id) { // 生成新的deploy记录（从instance,instance_component拷贝过来），并做相应的修改
			$instancePorvider = new AppInstanceProvider;
            $deployData = [
            		'app_instance_id'=>$appInstance->id,
            		'user_id' => $user['id'],
            		'user_name' => $user ['name']
            ];
			$deploy = $instancePorvider->newDeploy($instance_id, $deployData);
			$component = AppDeployComponent::where('app_deploy_id', $deploy->id)->where('name', $component->name)->first();
		}
		// $components = AppDeployComponent::where('app_deploy_id', $deploy_id)->where('name', '!=', $component->name);
		$componentProvider->setSection('container', $container);
		$componentProvider->setSection('config', $config);
		$componentProvider->setSection('depends', $depends);
		$componentProvider->setSection('hook', $hook);
		$componentProvider->setSection('monitor', $monitor);
		$componentProvider->setSection('log', $log);
		
		$componentProvider->setSection('build', $build);
		
		$env = PaasDeployEnv::where('location', $appInstance->node_group->isp)->firstOrFail();
		$componentProvider->setKubernetesEndpoint($env->k8s_endpoint);
		$componentProvider->setNameSpace($appInstance->name);
		$componentProvider->setAppId($appInstance->app_id);
		$componentProvider->setGroup($appInstance->node_group->id);
		$componentProvider->setDeployId($deploy->id);
		$definition = $componentProvider->getComponentJson();
		
		$component->name = $container['name'];
		$component->version = $container['version'];
		$component->definition = json_encode($definition);
		DB::beginTransaction();
		try {
			$component->save();
			AppInstanceProvider::checkDuplicateBinding($deploy->components, $component);
		} catch (\Exception $e) {
			DB::rollBack();
			throw $e;
		}
		DB::Commit();
		
		
		$other_components = AppDeployComponent::where('app_deploy_id', $deploy->id)->get();
		foreach ($other_components as $other_component) {
			$provider = new AppDeployComponentProvider($other_component);
			$provider->decodeDefinition();
			$depends = $provider->getDepend();
			if ($depends && in_array($component->name, $depends)) {
				$other_component->definition = $provider->getDefinition(); // redefined depends
				$other_component->save();
			}
		}
		
		return Response::Json(['deploy'=>$deploy, 'definition'=>$definition]);
	}
	
	public function show($id, Request $request) {
		if ($request->deploy_id) {
			$component = AppDeployComponent::findOrFail($id);
			$instance = $component->deploy->instance;
		} else {
			$component = AppInstanceComponent::findOrFail($id);
			$instance = $component->instance;
		}
		// $user = with(new ApiProvider())->getUserInfo(['token'=>Input::get ( 'token' ) ] );
		// $env = PaasDeployEnv::where ( 'location', $instance->node_group->isp )->where('company_id',$user['company_id'])->first ();
		$nodes = $instance->node_group->nodes;
		$componentProvider = new AppInstanceComponentProvider;
		$sections = $componentProvider->getSections($component);
		
		/*if (!$env && isset($sections['config']['service']['nodes'][0])) {
			foreach ($nodes as $node) {
				if ($node->ipaddress == $sections['config']['service']['nodes'][0]) {
					$sections['config']['service']['nodes'][0] = $node->public_ipaddress;
					break;
				}
			}
		}*/
		return Response::Json($sections);
	}
	
	public function destroy($id, Request $request) {
		$group_ids = [];
		$groups = with(new ApiProvider)->getUserRoleGroups(['token'=> $request->token]);
		if ($groups) {
			foreach ($groups as $group) {
				$group_ids[] = $group['id'];
			}
		}
		$instance = null;
		if ($request->deploy_id) {
			$deploy = AppDeploy::with(['instance' => function($query) use ($group_ids) {
				$query->with(['app' => function($query) use ($group_ids) {
					$query->whereIn('role_group_id', $group_ids);
				}]);
			}])->findOrFail($request->deploy_id);
			if ($deploy->instance->app) {
				$component = AppDeployComponent::findOrFail($id);
			} else {
			    throw new \Exception('无权限操作');
			}
			$instance = $deploy->instance;
		} else {
			$component = AppInstanceComponent::with(['instance' => function($query) use ($group_ids) {
				$query->with(['app' => function($query) use ($group_ids) {
					$query->whereIn('role_group_id', $group_ids);
				}]);
			}])->findOrFail($id);
			$instance = $component->instance;
			if (!empty($component->instance->app)) {
				$user = with(new ApiProvider())->getUserInfo(['token'=>Input::get ( 'token' ) ] );
				$deployData = [
						'app_instance_id'=>$component->app_instance_id,
						'user_id' => $user['id'],
						'user_name' => $user ['name']
				];
				$deploy = with(new AppInstanceProvider())->newDeploy($component->app_instance_id, $deployData);
				$component = AppDeployComponent::where('app_deploy_id', $deploy->id)->where('name', $component->name)->first();
				
			} else {
			    throw new \Exception('无权限操作');
			}
		}
		if ($instance->env_category == 'develop') {
			$component->forceDelete ();
			AppDeployComponentProvider::releaseDepends($component);
			return Response::Json(['deploy'=>$deploy]);
		} else {
			throw new \Exception('非开发测试环境不允许删除');
		}
	}
}
