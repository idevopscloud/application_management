<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\AppInstance;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\Response;
use App\Providers\Api\ApiProvider;
use App\PaasDeployEnv;
use App\Providers\Application\AppInstanceProvider;
use Illuminate\Support\Facades\Validator;
use App\Exceptions\ValidateException;
use App\App;
use App\AppDeploy;
use App\Providers\Application\AppInstanceComponentProvider;
use App\Events\DeployApprovalEvent;
use Illuminate\Support\Facades\Event;
use App\Approval;
use App\Providers\Application\AppDeployComponentProvider;
use App\Providers\Application\AppProvider;
use Illuminate\Support\Facades\DB;
use App\SvcBinding;
use App\AppInstanceComponent;
use App\AppNodeGroup;
use App\Exceptions\PaasException;
use App\Providers\Approval\ApprovalProvider;
use App\Exceptions\ApprovalException;

class AppInstanceController extends Controller
{
	public function index(Request $request) {
		$app_instances = [];
        if ($request->deploy_id) {
            $builder = new AppDeploy;
            $app_instances = AppDeploy::with('components')->findOrFail($deploy_id);
        } else if ($request->app_id) {
            $builder = new AppInstance;
            $request->app_id && $app_instances = $builder->where('app_id', $request->app_id)->get();
        } else if ($request->name) {
        	$app_instances = AppInstance::with(['node_group'=>function($query) {
        		$query->with('cluster');
        	}])->where('name', $request->name)->get();
        }
		return Response::Json($app_instances);
	}
	
	public function show($id, Request $request) {
		if ($request->deploy_id) {
			$deploy = AppDeploy::with('components')->findOrFail ( $request->deploy_id );
			$instance = $deploy->instance ()->with ( 'node_group' )->first ();
			$instance_components = $instance->components()->lists('name', 'id')->toArray();
			$deploy_components = $deploy->components()->whereRaw('created_at = updated_at')->lists('name', 'id')->toArray();
			$deploy->env_category = $instance->env_category;
			$deploy->diff_components = array_diff($deploy_components, $instance_components);
			if ($instance->node_group) {
				$deploy->node_group = $instance->node_group;
				$deploy->node_group_id = $instance->node_group->id;
			}
			/*$env = PaasDeployEnv::where ( 'location', $instance->node_group->isp )->where('company_id', $instance->app->company_id)->first ();
			if (!$env) {
				$instance->free = true;
			} else {
				$instance->free = false;
			}*/
			// parse component's nodes and ports
			foreach ($deploy->components as &$component) {
				$sections = with(new AppInstanceComponentProvider())->getSections($component);
				if (isset($sections['config']['service']['nodes'][0])) {
					if (isset($sections['config']['service']['port'][0])) {
						foreach ($sections['config']['service']['port'][0] as $port=>$protocol) {
							$net = explode ( ':', $port );
							$component['port'] = $net[1];
							break;
						}
					}
					if ($sections['config']['service']['nodes'][0] == '0.0.0.0') {
						$component->node = '0.0.0.0';
						continue;
					}
					$nodes = $instance->node_group->nodes;
					foreach ($nodes as $node) {
						if ($node->ipaddress == $sections['config']['service']['nodes'][0]) {
							if ($instance->free == false) {
								$component->node = $node->ipaddress;
							} else {
								$component->node = $node->public_ipaddress;
							}
							break;
						}
					}
				}
			}
			return Response::Json ( $deploy );
		}
		$app_instance = null;
		$builder = AppInstance::with ( 'components' );
		
		// 组件paas-api状态
		if ($request->action == 'paas_status') {
			$builder->with ( 'node_group' );
			$app_instance = $builder->findOrFail ( $id );
			if ($app_instance->node_group) {
				/*$location = $app_instance->node_group ['isp'];
				$env = PaasDeployEnv::where ( 'location', $location )->where('company_id', $app_instance->app->company_id)->first ();
				if (!$env) {
					$app_instance->free = true;
					$env = PaasDeployEnv::where ( 'location', $location )->firstOrFail ();
				} else {
					$app_instance->free = false;
				}*/
				$status = do_request_paas ( $app_instance->node_group->cluster->paas_api_url . '/applications/' . $app_instance->name, 'GET');
				$app_instance->paas_status = $status;
			}
			
		// 集群类型（私有或者共有）
		} else {
			$app_instance = $builder->findOrFail ( $id );
		}
		
		// parse component's nodes and ports
		foreach ($app_instance->components as &$component) {
			$sections = with(new AppInstanceComponentProvider())->getSections($component);
			if (isset($sections['config']['service']['nodes'][0])) {
				if (isset($sections['config']['service']['port'][0])) {
					foreach ($sections['config']['service']['port'][0] as $port=>$protocol) {
						$net = explode ( ':', $port );
						$component['port'] = $net[1];
						break;
					}
				}
				if ($sections['config']['service']['nodes'][0] == '0.0.0.0') {
					$component->node = '0.0.0.0';
					continue;
				}
				$nodes = $app_instance->node_group->nodes;
				foreach ($nodes as $node) {
					if ($node->ipaddress == $sections['config']['service']['nodes'][0]) {
						if ($app_instance->free == false) {
							$component->node = $node->ipaddress;
						} else {
							$component->node = $node->public_ipaddress;
						}
						break;
					}
				}
			}
		}
		return Response::Json ( $app_instance );
	}
	
	/**
	 * destroy app inst
	 * product inst is not allowed
	 * @param unknown $id
	 * @param Request $request
	 * @throws \Exception
	 */
	public function destroy($id, Request $request) {
		$app_instance = AppInstance::findOrFail ( $id );
		if ($app_instance->env_category == 'product') {
			throw new \Exception(trans('exception.not_allowed'));
		} else {
			with(new AppProvider())->destroyInstance($app_instance, $request);
			return Response::Json ( [ ] );
		}
	}
	
	/**
	 * clone or create
	 * 
	 * @param Request $request
	 * @throws ValidateException
	 */
	public function store(Request $request) {
		$validator = Validator::make($request->all(), [
			'app_id' => 'required|exists:apps,id',
			'name' => 'required|unique:app_instances,name',
			'node_group' => 'required|exists:app_node_groups,id'
			// 'env_category' => 'in:develop,product'
		]);
		
		if ($validator->fails()) {
			throw new ValidateException($validator->errors());
		}
		$ng = AppNodeGroup::find($request->node_group);
		if ($request->instance_id) { // clone from instance
			/*$validator = Validator::make($request->all(), [
					'app_id' => 'required|exists:apps,id',
					// 'env_category' => 'in:develop,product',
					'name' => 'required|unique:app_instances,name',
			]);
			
			if ($validator->fails()) {
				throw new ValidateException($validator->errors());
			}*/
			/*if ($env_category == 'product') {
				if (AppInstance::where('app_id', $app_id)->where('env_category', $env_category)->exists()) {
					throw new \Exception(trans('exception.product_exist'));
				}
			}*/
			$app_instance = AppInstanceProvider::cloneOne($request->instance_id, $request->name, $ng->env_category);
			return Response::Json($app_instance);
		}

       
		$apiProvider = new ApiProvider();
		$user = $apiProvider->getUserInfo(['token'=>Input::get('token')]);
		/*$app = App::with(['nodeGroups' => function($query) use ($env_category){
			$query->where('name', $env_category);
		}])->findOrFail($app_id);
        if (!isset($app->nodeGroups[0])) {
            throw new \Exception('No resource pool for develop');
        }*/
        
		$appInstanceData = [
			'name' => $request->name,
			'app_id' => $request->app_id,
			'env_category' => $ng->env_category,
			'node_group_id' => $request->node_group,
			'master_user_id' => $user['id'],
			'master_user_name' => $user['name'],
		];
		$app_instance = new AppInstance($appInstanceData);
		$app_instance->save();
		return Response::Json($app_instance);	
	}
	
	public function update() {
	
	}
	
	/**
	 * 同步组件到当前实例
	 * 
	 * @param Request $request
	 * @throws ValidateException
	 * @throws \Exception
	 */
	public function syncComponent(Request $request) {
		$validator = Validator::make($request->all(), [
				'instance_id' => 'required|exists:app_instances,id',
				'build_number' => 'required|exists:app_deploys,id',
		]);
		if ($validator->fails()) {
			throw new ValidateException($validator->errors());
		}
		
		$instancePorvider = new AppInstanceProvider;
		$instance = AppInstance::findOrFail ( $request->instance_id );
		/*
		if ($instance->env_category != 'product') {
			throw new \Exception ( 'Not allowed' );
		}*/
		
		$build = AppDeploy::with ( 'components' )->findOrFail ( $request->build_number );
		$user = with ( new ApiProvider () )->getUserInfo ( [ 
				'token' => Input::get ( 'token' ) 
		] );
		$deployData = [ 
				'app_instance_id' => $instance->id,
				'user_id' => $user ['id'],
				'user_name' => $user ['name'] 
		];
		$deploy = $instancePorvider->newDeploy ( $instance->id, $deployData );
		$deploy_components = $deploy->components ()->get ();
		$deploy_coms = $update_coms = [];
		foreach ( $build->components as $component ) {
			$build_coms [$component->name] = $component;
			foreach ( $deploy_components as $deploy_component ) {
				$deploy_coms [$deploy_component->name] = $deploy_component;
				if ($deploy_component->name == $component->name) {
					$update_coms [] = [ 
							'from' => $component,
							'to' => $deploy_component 
					];
				}
			}
		}
		$new_coms = array_diff_key ( $build_coms, $deploy_coms );
		$remove_coms = array_diff_key ( $deploy_coms, $build_coms );
		foreach ($new_coms as $new) {
			AppDeployComponentProvider::cloneOne($new['id'], $deploy->id);
		}
		foreach ($update_coms as $update) {
			AppInstanceComponentProvider::syncComponent($update['from'], $update['to']);
		}
		foreach ($remove_coms as $remove) {
			$remove->forceDelete();
			AppDeployComponentProvider::releaseDepends($remove);
		}
		return Response::Json($deploy);
	}
	
	/**
	 * 部署
	 * 
	 * @param Request $request
	 * @throws ValidateException
	 */
	public function deploy(Request $request) {
		$instancePorvider = new AppInstanceProvider;
		$instance = AppInstance::with(['node_group', 'app'])->findOrFail($request->instance_id);
		$user = with ( new ApiProvider () )->getUserInfo ( [
				'token' => $request->token
		] );
		
		// deploy product
		if ($instance->env_category == 'product') {
			
			$validator = Validator::make($request->all(), [
					'comment' => 'required'
					]);
			if ($validator->fails()) {
				throw new ValidateException($validator->errors());
			}
			
			if (with(new ApprovalProvider)->duplicateCheck($instance->id, $user['id'])) {
				throw new ApprovalException(trans('exception.approval_repeat_deploy_request'));
			}
			
			if (!$request->deploy_id) {
				$validator = Validator::make ( $request->all (), [ 
						'instance_id' => 'required|exists:app_instances,id' 
				] );
				if ($validator->fails ()) {
					throw new ValidateException ( $validator->errors () );
				}
				
				$deployData = [ 
						'app_instance_id' => $request->instance_id,
						'user_id' => $user ['id'],
						'user_name' => $user ['nickname'] 
				];
				$deploy = $instancePorvider->newDeploy ( $request->instance_id, $deployData );
			} else {
				$deploy = AppDeploy::findOrFail($request->deploy_id);
			}
			if ($request->instance_id != $deploy->instance->id) {
				throw new \Exception(trans('exception.params_error'));
			}
			
			$env = PaasDeployEnv::where ( 'location', $instance->node_group->isp )->firstOrFail ();
			$re_deploy = 0;
			try {
				$status = do_request_paas ( "{$env->paas_api_url}/applications/{$instance->name}?summary=y", 'GET',[],null,$env->api_key  );
				if (!isset ( $status ['stack_info'] ['stack_status'] )) { // stopped
					$re_deploy = 1;
				}
			} catch (\Exception $e) {
				if ($e->getCode () == '404') {
					$re_deploy = 1;
				}
			}
			
			AppInstanceProvider::checkDuplicateBinding($deploy->components);
			AppInstanceProvider::checkMemUsage ( $deploy->id, $instance->id, $instance->app->company_id, $request->token, $re_deploy);
			$approvalData = [
					'status' => 0,
					'type' => 'deploy',
					'data' => json_encode($deploy->toArray()),
					'user_id' => $user['id'],
					'user_name' => $user['name'],
					'comment' => $request->comment
			];
			
			$group = with(new ApiProvider)->getGroups(['token'=>$request->token, 'id'=>$instance->app->role_group_id]);
			if ($group['roles']) {
				foreach($group['roles'] as $role) {
					if (strpos($role['name'], '-master') > 0)
						$approvalData['approval_role_id'] = $role['id'];
				}
			}
			$approval = new Approval($approvalData);
			$approval->save();
			return Response::Json($deploy); 
			
		// deploy dev-test	
		} else {
			if (! $request->deploy_id) {
				$validator = Validator::make ( $request->all (), [ 
						'instance_id' => 'required|exists:app_instances,id' 
				] );
				if ($validator->fails ()) {
					throw new ValidateException ( $validator->errors () );
				}
				
				$deployData = [ 
						'app_instance_id' => $request->instance_id,
						'user_id' => $user ['id'],
						'user_name' => $user ['nickname'] 
				];
				$deploy = $instancePorvider->newDeploy ( $request->instance_id, $deployData );
			} else {
				$deploy = AppDeploy::findOrFail ( $request->deploy_id );
			}
			if ($request->instance_id != $deploy->instance->id) {
				throw new \Exception(trans('exception.params_error'));
			}
			$definition = $instancePorvider->setModel ( $deploy )->getDefinition ();
			$env = PaasDeployEnv::where ( 'location', $instance->node_group->isp )->firstOrFail ();
			
			$re_deploy = 0;
			try {
				$status = do_request_paas ( "{$env->paas_api_url}/applications/{$deploy->instance->name}?summary=y", 'GET',[],null,$env->api_key  );
				if (!isset ( $status ['stack_info'] ['stack_status'] )) { // stopped
					$re_deploy = 1;
				}
			} catch (\Exception $e) {
				if ($e->getCode () == '404') {
					$re_deploy = 1;
				}
			}
			
			DB::beginTransaction ();
			try {
				with ( new AppInstanceProvider () )->syncComponentFromDeploy ( $deploy->id, $instance->id, $instance->app->company_id, $request->token, $re_deploy );
				$url = $env->paas_api_url . '/applications';
				$result = do_request_paas ( $url, 'POST', $definition,null,$env->api_key  );
				
				$deploy->is_deploy = 1;
				$deploy->status = 0;
				$deploy->save ();
			} catch (PaasException $e) {
				$rollback_deploy = AppDeploy::where('id', '<', $deploy->id)->where('app_instance_id', $instance->id)->orderBy('id', 'desc')->where('status',1)->first();
				if ($rollback_deploy) {
					with ( new AppInstanceProvider () )->syncComponentFromDeploy ( $rollback_deploy->id, $instance->id, $instance->app->company_id, $request->token);
				} else {
					AppInstanceProvider::recoverMemUsage($instance->id, $instance->app->company_id, $request->token);
				}

				$message = preg_replace("/\s/", '_', $e->getMessage());
				throw new \Exception(trans('exception.request_paas_error', ['message'=>trans("exception.attributes.{$message}")]), $e->getCode());
				DB::rollBack();
			} catch (\Exception $e) {
				DB::rollBack();
				throw $e;
			}
			DB::Commit();
		}
        return Response::Json($deploy);
		
	}
	
	/**
	 * 清除运行环境
	 * @param Request $request
	 * @throws Exception
	 */
	public function clean(Request $request) {
		$result = [];
		$instance = AppInstance::findOrFail($request->instance_id);
		$env = PaasDeployEnv::where('location', $instance->node_group->isp)->firstOrFail();
		$url = "{$env->paas_api_url}/applications/{$instance->name}";
		if ($instance->env_category == 'product') {
			$validator = Validator::make($request->all(), [
					'comment' => 'required'
			]);
			if ($validator->fails()) {
				throw new ValidateException($validator->errors());
			}
			
			$user = with ( new ApiProvider () )->getUserInfo ( [
					'token' => $request->token
			] );
			if (with(new ApprovalProvider)->duplicateCheck($instance->id, $user['id'])) {
				throw new ApprovalException(trans('exception.approval_repeat_request'));
			}
			$approvalData = [
				'status' => 0,
				'type' => 'instance_clean',
				'data' => json_encode(['url'=>$url, 'instance'=>$instance, 'env'=>$env, 'company_id'=>$instance->app->company_id]),
				'user_id' => $user['id'],
				'user_name' => $user['nickname'],
				'comment' => $request->comment
			];
			$group = with(new ApiProvider)->getGroups(['token'=>$request->token, 'id'=>$instance->app->role_group_id]);
			if ($group['roles']) {
				foreach($group['roles'] as $role) {
					if (strpos($role['name'], '-master') > 0)
						$approvalData['approval_role_id'] = $role['id'];
				}
			}
			$approval = new Approval($approvalData);
			$approval->save();
			return Response::Json([]);
		}
		DB::beginTransaction ();
		try {
			SvcBinding::where ( 'instance_id', $instance->id )->forceDelete ();
			$result = do_request_paas($url, 'DELETE',[],null,$env->api_key );
		} catch (\Exception $e) {
			DB::rollBack();
			throw $e;
		} finally {
			AppInstanceProvider::recoverMemUsage($instance->id, $instance->app->company_id, $request->token);
		}
		DB::Commit();
		return Response::Json($result);
	}
	
	public function cancel(Request $request) {
		$result = [];
		$instance = AppInstance::findOrFail($request->instance_id);
		DB::beginTransaction ();
		try {
			$env = PaasDeployEnv::where('location', $instance->node_group->isp)->firstOrFail();
			$url = "{$env->paas_api_url}/applications/{$instance->name}/actions?action=cancel_update";
			$result = do_request_paas($url, 'POST',[],null,$env->api_key );
			$deploy = AppDeploy::where('app_instance_id', $instance->id)->where('status', 1)->orderBy('id', 'desc')->firstOrFail();
			with ( new AppInstanceProvider () )->syncComponentFromDeploy ( $deploy->id, $instance->id,$instance->app->company_id,$request->token );
		} catch (PaasException $e) {
			DB::rollBack();
			throw new \Exception(trans('exception.cancel_upadte_error'));
		} catch (\Exception $e) {
			DB::rollBack();
			throw $e;
		} 
		DB::Commit();
		return Response::Json($result);
	}
	
	/**
	 * pod restart
	 * @param Request $request
	 * @throws ValidateException
	 */
	public function podRestart(Request $request) {
		$validator = Validator::make($request->all(), [
				'instance_id' => 'required|exists:app_instances,id',
				'pod' => 'required',
		]);
		if ($validator->fails()) {
			throw new ValidateException($validator->errors());
		}
		$instance = AppInstance::with(['node_group', 'app'])->findOrFail($request->instance_id);
		$env = PaasDeployEnv::where('location', $instance->node_group->isp)->firstOrFail();
		$url = "{$env->paas_api_url}/applications/{$instance->name}/pods/{$request->pod}";
		$user = with ( new ApiProvider () )->getUserInfo ( [
				'token' => $request->token
		] );
		
		// deploy product
		if ($instance->env_category == 'product') {
			$validator = Validator::make($request->all(), [
					'comment' => 'required'
			]);
			if ($validator->fails()) {
				throw new ValidateException($validator->errors());
			}
			
			if (with(new ApprovalProvider)->duplicateCheck($instance->id, $user['id'])) {
				throw new ApprovalException(trans('exception.approval_repeat_request'));
			}
			
			$approvalData = [
				'status' => 0,
				'type' => 'pod_restart',
				'data' => json_encode(['url'=>$url, 'env'=>$env, 'pod'=>['name'=>$request->pod], 'instance'=>$instance]),
				'user_id' => $user['id'],
				'user_name' => $user['nickname'],
				'comment' => $request->comment
			];
			$group = with(new ApiProvider)->getGroups(['token'=>$request->token, 'id'=>$instance->app->role_group_id]);
			if ($group['roles']) {
				foreach($group['roles'] as $role) {
					if (strpos($role['name'], '-master') > 0)
						$approvalData['approval_role_id'] = $role['id'];
				}
			}
			$approval = new Approval($approvalData);
			$approval->save();
			return Response::Json([]);
		} else {
			$result = do_request_paas($url, 'DELETE', [], null, $env->api_key );
			return Response::Json($result);
		}
	}
}
