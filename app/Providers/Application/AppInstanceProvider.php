<?php namespace App\Providers\Application;

use App\Exceptions\AppInstanceException;
use App\AppInstanceComponent;
use App\AppInstance;
use App\Providers\Api\ApiProvider;
use Illuminate\Support\Facades\Input;
use Illuminate\Support\Facades\DB;
use App\AppNodeGroup;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use App\AppDeploy;
use App\AppDeployComponent;
use App\SvcBinding;
use Illuminate\Database\Eloquent\Collection;

class AppInstanceProvider {
	
	protected $model;
	
	public function setModel($model) {
		$this->model = $model;
		return $this;
	}
	
	/**
	 * 检查组件组内是否有重复的绑定
	 * 
	 * @param Collection $components
	 * @param AppDeployComponent $component 判断是否有重置(0.0.0.0)的设置
	 * @throws \Exception
	 */
	public static function checkDuplicateBinding(Collection $components, AppDeployComponent $component = null) {
		foreach($components as $component_model) {
			if (get_class($component_model) != 'App\AppDeployComponent')
				continue;
			$provider = new AppDeployComponentProvider($component_model);
			$provider->decodeDefinition();
			$config = $provider->getConfig();
			if ($component) {
				if ($component->name == $component_model->name && isset($config['service']['nodes']) && in_array('0.0.0.0', $config['service']['nodes'])) {
					throw new \Exception(trans('exception.deprecated_publicips', ['name'=>$component_model->name]));
				}
			} else {
				if (isset($config['service']['nodes']) && in_array('0.0.0.0', $config['service']['nodes'])) {
					throw new \Exception(trans('exception.deprecated_publicips', ['name'=>$component_model->name]));
				}
			}
			/**
			 * 判断组件之间是否有重复绑定
			 * 未部署前，服务配置只存在于组件的definition中，需要逐个判断
			 */
			if (isset($config['service']['port'])) {
				$node = $config['service']['nodes'][0];
				foreach ( $config ['service']['port'] as $ports ) {
					foreach ($ports as $port=>$protocol) {
						$net = explode ( ':', $port );
						if (!isset($net[1])) {
							continue;
						}
						if(!isset($bindings[$net[1].$node])) {
							$bindings[$net[1].$node] = 1;
						} else {
							throw new \Exception(trans('exception.duplicate_binding', ['name'=>$component_model->name, 'ipaddress'=>$node, 'port'=>$net[1]]));
						}
		
					}
				}
			}
		}
	}
	
	/**
	 * 拼装paas-api json
	 */
	public function getDefinition() {
		$definition = [
				"heat_template_version"=>"2013-05-23",
				"description"=>"Heat template to deploy kubernetes replication controllers and services to an existing host",
				"resources" =>[]
		];
		$components = $this->model->components()->get();
		$defs = json_decode("{}");
		$bindings = [];
		foreach($components as $component) {
			$provider = new AppDeployComponentProvider($component);
			$provider->decodeDefinition();
			$config = $provider->getConfig();
			if (isset($config['service']['nodes']) && in_array('0.0.0.0', $config['service']['nodes'])) {
				throw new \Exception(trans('exception.deprecated_publicips', ['name'=>$component->name]));
			}
			/**
			 * 判断组件之间是否有重复绑定
			 * 未部署前，服务配置只存在于组件的definition中，需要逐个判断
			 */
			if (isset($config['service']['port'])) {
				$node = $config['service']['nodes'][0];
				foreach ( $config ['service']['port'] as $ports ) {
					foreach ($ports as $port=>$protocol) {
						$net = explode ( ':', $port );
						if (!isset($net[1])) {
							continue;
						}
						if(!isset($bindings[$net[1].$node])) {
							$bindings[$net[1].$node] = 1;
						} else {
							throw new \Exception(trans('exception.duplicate_binding', ['name'=>$component->name, 'ipaddress'=>$node, 'port'=>$net[1]]));
						}
						
					}
				}
			}
			$def = json_decode($component->definition);
			$svc = $svc_name = null;
			$rc = $rc_name = null;
			foreach ($def as $k=>$v) {
				if ($v->type ==='GoogleInc::Kubernetes::ReplicationController'){
					if (isset($v->build)) {
						unset($v->build);
					}
					$rc = $v;
					$rc_name = $k;
					
				} else if($v->type === 'GoogleInc::Kubernetes::Service') {
					$svc = $v;
					$svc_name = $k;
				}
			}
			if ($svc !== null) {
				$defs->$svc_name = $svc;
			}
			$defs->$rc_name = $rc;
		}
		$definition["resources"] = json_decode(json_encode($defs));
		return $definition;
	}
	
	/**
	 * 克隆实例
	 * @param unknown $instance_id
	 * @param unknown $name
	 * @param unknown $env_category
	 * @throws \Exception
	 * @throws Exception
	 * @return multitype:\App\AppInstance unknown
	 */
	public static function cloneOne($instance_id, $name, $env_category) {
		$appInstanceData = AppInstance::select(['app_id', 'node_group_id'])->findOrFail($instance_id)->toArray();
		$appInstanceData['name'] = $name;
		/*if ($appInstanceData['env_category'] != $env_category) {
			$appInstanceData['env_category'] = $env_category;
			$nodeGroup = AppNodeGroup::where('app_id', $appInstanceData['app_id'])->where('name', $env_category)->first();
			
			if ($nodeGroup) {
				$appInstanceData['node_group_id'] = $nodeGroup->id;
			} else {
				throw new \Exception(trans('exception.no_resource_pool', ['env_category'=>$env_category]));
			}
		}*/
		$user = with(new ApiProvider())->getUserInfo(['token'=>Input::get('token')]);
		$appInstanceData['master_user_id'] = $user['id'];
		$appInstanceData['master_user_name'] = $user['name'];
		DB::beginTransaction();
		try {
			$appInstance = new AppInstance($appInstanceData);
			$appInstance->env_category = $env_category;
			$appInstance->save();
			$components = AppInstanceComponentProvider::cloneAll($instance_id, $appInstance->id);
		}catch (\Exception $e) {
			DB::rollBack();
			throw $e;
		} finally {
			DB::Commit();
		}
		return ['instance'=>$appInstance, 'components'=>$components];
	}
	
	/**
	 * approval deploy 
	 * 
	 * @param AppDeploy $deploy
	 * @param string $comment
	 */
	public function ApprovalDeploy(AppDeploy $deploy, string $comment) {
		$deploy->is_approval = 1;
		$deploy->comment = $comment;
		$deploy->save();
	}
	
	/**
	 * reject deploy
	 * 
	 * @param AppDeploy $deploy
	 * @param string $comment
	 */
	public function rejectDeploy(AppDeploy $deploy, string $comment) {
		$deploy->is_approval = 2;
		$deploy->comment = $comment;
		$deploy->save();
	}
	
	/**
	 * Create a new deploy with components from instance 
	 * 
	 * @throws Exception
	 * @return unknown
	 */
	public function newDeploy($instance_id, $deployData) {
		DB::beginTransaction();
		try {
			$deploy = new AppDeploy($deployData);
			$deploy->save();
			$this->syncComponentFromInstance($instance_id, $deploy->id);
		}catch (\Exception $e) {
			DB::rollBack();
			throw $e;
		} finally {
			DB::Commit();
		}
		return $deploy;
	}
	
	/**
	 * sync components from instance
	 * @param unknown $instance_id
	 * @param unknown $deploy_id
	 */
	public function syncComponentFromInstance($instance_id, $deploy_id) {
		$appInstance = AppInstance::select(['id'])->with('components')->findOrFail($instance_id);
		foreach ( $appInstance->components as $component ) {
			$componentData = [
					'definition' => $component->definition,
					'name' => $component->name,
					'version' => $component->version,
					'app_deploy_id' => $deploy_id 
			];
			$deployComponent = new AppDeployComponent($componentData);
			$deployComponent->save();
		}
	}
	
	/**
	 * Sync instance's components from deploy record 
	 * 
	 * @param int $deploy_id from deploy record,this record must belongs to instance
	 * @param int $instance_id identifier of instance which would be synced
	 */
	public function syncComponentFromDeploy($deploy_id, $instance_id, $company_id, $token, $re_deploy = 0) {
		$deploy_coms = $instance_coms = [];
		$instance = AppInstance::with('deploys')->findOrFail($instance_id);
		$deploy_components = AppDeployComponent::where('app_deploy_id', $deploy_id)->get();
		$instance_components = AppInstanceComponent::where ( 'app_instance_id', $instance_id )->get();
		DB::beginTransaction();
		try {
			$mem = 0;
			foreach ( $deploy_components as $deploy_component ) {
				$deploy_coms[$deploy_component->name] = $deploy_component;
				$dcomponentProvider = new AppInstanceComponentProvider();
				$dSectionData = $dcomponentProvider->getSections($deploy_component);
				$re_deploy && $mem += $dcomponentProvider->getMem(); // 重新部署运行环境，取当前构建版本的内存总数
				foreach ( $instance_components as $instance_component ) {
					$componentProvider = new AppInstanceComponentProvider();
					$iSectionData = $componentProvider->getSections($instance_component);
					
					/**
					 * 更新的组件
					 */
					if ($instance_component->name == $deploy_component->name) { 
						!$re_deploy && $mem += $dcomponentProvider->getMem() - $componentProvider->getMem();
						
						$instance_component->definition = $deploy_component->definition;
						$instance_component->version = $deploy_component->version;
						$instance_component->save();
						
						if ($componentProvider->ifSvcExist($instance_component)) {
							$componentProvider->saveSvcBinding($instance_component);
							$config = arrayRecursiveDiff($iSectionData['config'], $dSectionData['config']); // remove binding
							if (isset($config['service']['nodes'])) {
								$componentProvider->removeSvcBinding($instance_component, $config['service']);
							}
						}
					}
					
				} 
			}
			foreach ( $instance_components as $component ) {
				$instance_coms[$component->name] = $component;
			}
			$add_coms = array_diff_key($deploy_coms, $instance_coms);
			$remove_coms  = array_diff_key($instance_coms, $deploy_coms);
			/**
			 * 新增的组件
			 */
			foreach ($add_coms as $component) { 
				$instance_component = new AppInstanceComponent();
				$instance_component->name = $component->name;
				$instance_component->definition = $component->definition;
				$instance_component->version = $component->version;
				$instance_component->app_instance_id = $instance_id;
				$instance_component->save ();
				$componentProvider = new AppInstanceComponentProvider();
				if ($componentProvider->ifSvcExist($instance_component)) {
					$componentProvider->saveSvcBinding($instance_component);
				}
				!$re_deploy && $mem += $componentProvider->getMem();
			}
			/**
			 * 删除的组件
			 */
			foreach ($remove_coms as $component) {
				$componentProvider = new AppInstanceComponentProvider();
				$componentProvider->getSections($component);
				$config = $componentProvider->getConfig();
				if (isset($config['service']) )
				    $componentProvider->removeSvcBinding($component, $config['service']);
				$component->forceDelete ();
				!$re_deploy && $mem -= $componentProvider->getMem();
			}
			$mem && with(new ApiProvider)->updateCompany(['token'=>$token, 'id'=>$company_id, 'action'=>'add', 'mem'=>$mem]);
		} catch (\Exception $e) {
			DB::rollBack();
			throw $e;
		}
		DB::Commit();
    }
    
    /**
     * check mem usage if enough
     * 
     * @param unknown $deploy_id
     * @param unknown $instance_id
     * @param unknown $company_id
     * @param unknown $token
     * @param number $re_deploy
     * @throws \Exception
     * @throws Exception
     */
    public static function checkMemUsage($deploy_id, $instance_id, $company_id, $token, $re_deploy = 0) {
    	$deploy_coms = $instance_coms = [];
    	$deploy_components = AppDeployComponent::where('app_deploy_id', $deploy_id)->get();
    	$instance_components = AppInstanceComponent::where ( 'app_instance_id', $instance_id )->get();
    	try {
    		$mem = 0;
    		foreach ( $deploy_components as $deploy_component ) {
    			$deploy_coms[$deploy_component->name] = $deploy_component;
    			$dcomponentProvider = new AppInstanceComponentProvider();
    			$dcomponentProvider->decodeDefinition($deploy_component);
    			$re_deploy && $mem += $dcomponentProvider->getMem(); // 重新部署运行环境，取当前构建版本的内存总数
    			
    			foreach ( $instance_components as $instance_component ) {
    				$componentProvider = new AppInstanceComponentProvider();
    				$componentProvider->decodeDefinition($instance_component);
    				/**
    				 * 更新的组件
    				*/
    				if (!$re_deploy && $instance_component->name == $deploy_component->name) {
    					$mem += $dcomponentProvider->getMem() - $componentProvider->getMem();
    				}
    					
    			}
    		}
    		
    		if (!$re_deploy) {
	    		foreach ( $instance_components as $component ) {
	    			$instance_coms[$component->name] = $component;
	    		}
	    		$add_coms = array_diff_key($deploy_coms, $instance_coms);
	    		$remove_coms  = array_diff_key($instance_coms, $deploy_coms);
	    		/**
	    		 * 新增的组件
	    		*/
	    		foreach ($add_coms as $component) {
	    			$mem += $componentProvider->getMem();
	    		}
	    		/**
	    		 * 删除的组件
	    		 */
	    		foreach ($remove_coms as $component) {
	    			$mem -= $componentProvider->getMem();
	    		}
    		}
    		$company = with(new ApiProvider)->getCompany(['token'=>$token, 'id'=>$company_id]);
    		if ($company && ($company['mem_limit'] - $company['mem_usage'] < $mem)) {
    			throw new \Exception(trans('exception.mem_usage_not_enough'));
    		}
    	} catch (\Exception $e) {
    		throw $e;
    	}
    }
    
    /**
     * recover mem usage from instance,this method will recover mem usage.
     * for rollback
     * 
     * @param int $instance_id recovered instance
     * @param string $company_id team of this app
     * @param string $token token for auth
     */
    public static function recoverMemUsage($instance_id, $company_id, $token) {
    	$mem = 0;
    	$instance_components = AppInstanceComponent::where ( 'app_instance_id', $instance_id )->get();
    	foreach ( $instance_components as $instance_component ) {
    		$componentProvider = new AppInstanceComponentProvider();
    		$componentProvider->getSections($instance_component);
    		$mem -= $componentProvider->getMem();
    	}
    	$mem && with(new ApiProvider)->updateCompany(['token'=>$token, 'id'=>$company_id, 'action'=>'add', 'mem'=>$mem]);
    }
}


