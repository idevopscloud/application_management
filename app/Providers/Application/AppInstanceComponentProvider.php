<?php namespace App\Providers\Application;

use App\Exceptions\AppInstanceComponentException;
use App\AppInstanceComponent;
use App\AppInstance;
use App\PaasDeployEnv;
use App\AppDeployComponent;
use App\SvcBinding;
use App\AppDeploy;
use App\App;
use Illuminate\Validation\ValidationException;
use App\Exceptions\ValidateException;
use App\Providers\Api\ApiProvider;
use Illuminate\Support\Facades\Input;
class AppInstanceComponentProvider {
	
	/**
	 * sections
	 * @var mixed
	 */
	protected $container = [];
	protected $config = [];
	protected $depends = [];
	protected $hook = [];
	protected $monitor = [];
	protected $log = [];
	
	protected $build = [];
	
	protected $app_id;
	
	protected $component = [ 
			'depends_on' => [ ],
			'type' => "GoogleInc::Kubernetes::ReplicationController",
			'properties' => [ 
					'kubernetes_endpoint' => '',
					'token' => '',
					'rolling_updates' => [ 
							'batch_percentage' => 50,
							'pause_time' => 5 
					] 
			] 
	];
	
	protected $svc = null;
	
	protected $apiVersion = 'v1';
	
	protected $internalId;
	
	protected $metadata;
	
	protected $initialDelaySeconds = 3;
	
	protected $kubernetesEndpoint;
	
	protected $version;
	
	protected $deploy_id;
	
	protected $instance_id;
	
	protected $origin_depends = [];
	
	const MAX_PORT = 42000;
	const MIN_PORT = 40000;

	public function __construct() {
		
	}
	
	public static function cloneAll($from_instance_id, $to_instance_id) {
		$news = [];
		$instance = AppInstance::with('node_group')->findOrFail($to_instance_id);
		// $env = PaasDeployEnv::where('location', $instance->node_group->isp)->firstOrFail();
		$cluster = $instance->node_group->cluster;

		$from_instance = AppInstance::with('node_group')->findOrFail($from_instance_id);
		$registry_changed = false; // diff registry
		if ($cluster->registry_id == $from_instance->node_group->cluster->registry_id) {
			$registry_changed = true;
		}
		$components = AppInstanceComponent::where ( 'app_instance_id', $from_instance_id )->select ( 'definition', 'name', 'version' )->get ();
		foreach ( $components as $component ) {
			$provider = new AppInstanceComponentProvider ();
			$provider->decodeDefinition ( $component );
			/*if ($cluster->company_id) {
				$provider->resetSvcIpaddress ( '0.0.0.0' );
			} else {*/
				$nodes = $instance->node_group->nodes()->get()->toArray();
				$rand = array_rand($nodes);
				$node = $nodes[$rand];
				$provider->resetSvcIpaddress ( $node['ipaddress'], $node['public_ipaddress'] );
				$provider->resetSvcPort($node['public_ipaddress']);
			// }

			if ($registry_changed) {
				$build = $provider->getBuild();
				$build['sync_from'] = $provider->getImage();
				$provider->setSection('build', $build);

				$registry = with(new ApiProvider)->getRegistry(['token' => Input::get('token'), 'id' => $cluster->registry_id]);
				$image = $provider->getImage();
				$blade = parse_url($image);
				$image = $registry['host'] . $blade['path'];
				$provider->setImage($image);
			}

			$provider->setInstanceId ( $instance->id );
			$provider->setKubernetesEndpoint ( $cluster->k8s_endpoint );
			$provider->setNameSpace ( $instance->name);
			// $provider->setAppId($instance->app_id);
			$provider->setGroup($instance->node_group->id);
			$provider->genInternalId();
			
			$new = new AppInstanceComponent($component->toArray());
			$new->app_instance_id = $to_instance_id;
			$new->definition = json_encode($provider->getComponentJson(true));
			$new->save();
			$news[] = $new;
		}
		
		return $news;
	}
	
	/**
	 * @deprecated
	 * @param unknown $component
	 * @param unknown $version
	 * @return unknown
	 */
	public static function updateVersion($component, $version) {
		$provider = with(new AppInstanceComponentProvider());
		$sections = $provider->decodeDefinition($component);
		$provider->setVersion ($version);
		$component->version = $version;
		$provider->genInternalId();
		$component->definition = json_encode($provider->getComponentJson());
		$component->save();
		
		return $component;
	}
	
	/**
	 * sync from one to other one
	 * 
	 * @param unknown $from_component
	 * @param unknown $to_component
	 * @return unknown
	 */
	public static function syncComponent($from_component, $to_component) {
		$from_provider = with(new AppInstanceComponentProvider());
		$to_provider = with(new AppInstanceComponentProvider());
		$from_sections = $from_provider->getSections($from_component);
		$to_provider->decodeDefinition($to_component);
		$diff = [];
		foreach ( $from_sections as $section => $data ) {
			if (isset ( $to_provider->$section ) ) {
				if (is_array ( $to_provider->$section ) && is_array ( $data ) && !empty($data) ) {
					$sectionDiff = arrayRecursiveDiff ( $data, $to_provider->$section );
					count ( $sectionDiff ) > 0 && $diff [$section] = $sectionDiff;
				} else {
					$diff [$section] = $data;
				}
			}
		}
		if (!empty ( $diff ['depends'] ) || $to_provider->getVersion () != $from_provider->getVersion ()) {
			!empty ( $diff ['depends'] ) && $to_provider->setSection ( 'depends', $from_provider->depends );
			$to_provider->setVersion ( $from_provider->getVersion () );
			$to_provider->genInternalId ();
		}
		if (!empty($diff['config']['volume'])) {
			$to_provider->setVolume( $from_provider->getVolume () );
			$to_provider->genInternalId ();
		}
		if (!empty($diff['hook'])) {
			$to_provider->setSection( 'hook', $from_provider->getHook () );
			$to_provider->genInternalId ();
		}
		
		if (!empty($diff['build'])) {
			$to_provider->setSection( 'build', $from_provider->getBuild () );
			$to_provider->genInternalId ();
		}
		
		$to_provider->setDeployId($to_component->app_deploy_id);
		// $to_provider->setImage($from_provider->getImage());
		
		$to_component->definition = json_encode($to_provider->getDefinition());
		$to_component->version = $to_provider->getVersion();
		
		$to_component->save();
	
		return $to_component;
	}
	
	public function setSection($key, $value) {
		isset($this->$key) && $this->$key = $value;
	}
	
	public function setVersion($version) {
		$this->container['version'] = $version;
	}
	
	public function getVersion() {
		return $this->container['version'];
	}
	
	public function setAppId($app_id) {
		$this->app_id = $app_id;
	}
	
	public function setNameSpace($namespace) {
		$this->namespace = $namespace;
	}
	
	public function setKubernetesEndpoint($endpoint) {
		$this->kubernetesEndpoint = $endpoint;
	}

    public function setGroup($group) {
        $this->group = $group;
    }
    
    public function genInternalId() {
    	$this->internalId = uniqid();
    }
    
    public function setDeployId($deploy_id) {
    	$this->deploy_id = $deploy_id;
    }
    
    public function setInstanceId($instance_id) {
    	$this->instance_id = $instance_id;
    }

    /**
     * get component definition
     * 
     * @return multitype:multitype:multitype: string multitype:string multitype:number    multitype:string multitype:string multitype:string
     */
	public function getComponentJson($ignore_depends_exist = false) {
		if (! $this->internalId) {
			$this->genInternalId ();
		}
		$this->component ['properties'] ['kubernetes_endpoint'] = $this->kubernetesEndpoint;
		$this->component ['properties'] ['apiversion'] = $this->apiVersion;
		$this->component ['properties'] ['namespace'] = $this->namespace;
		$this->component ['properties'] ['definition'] ['apiVersion'] = $this->apiVersion;
		$this->component ['properties'] ['definition'] ['kind'] = "ReplicationController";
		
		$this->joinContainer ();
		$this->joinConfig ();
		$this->joinDepend ($ignore_depends_exist);
		$this->joinHook ();
		$this->joinMonitor ();
		$this->joinLog ();
		$this->joinBuild ();
		if ($this->group)
			$this->component ['properties'] ['definition'] ['spec'] ['template'] ['spec'] ['nodeSelector'] = [ 
					"idevops.env.{$this->group}" => 'Y' 
			];
		
		$this->joinSvc ();
		
		$json = [ 
				$this->container ['name'] . '-rc' => $this->component 
		];
		if (isset ( $this->svc )) {
			$this->svc ['depends_on'] = [ 
					$this->container ['name'] . '-rc' 
			];
			$json [$this->container ['name'] . '-svc'] = $this->svc;
		}
		return $json;
	}
	
	
	public function getDefinition() {
		return $this->getComponentJson();
	}
	
	/**
	 * generate svc section
	 * 
	 * @throws AppInstanceComponentException
	 */
	protected function joinSvc() {
		if ( !empty($this->config['service']['nodes'])  && !empty($this->config['service']['port']) 
            && is_array($this->config['service']['nodes']) && is_array($this->config['service']['port']) ) 
		{
			$this->svc = [ 
					'type' => 'GoogleInc::Kubernetes::Service',
					'properties' => [ 
							'definition' => [ 
									'kind' => 'Service' 
							],
							'token' => '',
							'kubernetes_endpoint' => $this->kubernetesEndpoint,
							'namespace' => $this->namespace,
							'apiversion' => $this->apiVersion 
					] 
			];
			
			$definition = &$this->svc['properties']['definition'];
			$definition['spec']['selector']['name'] = $this->container['name'];
			
			// ipaddress bind
			$definition['spec']['deprecatedPublicIPs'] = $this->config['service']['nodes'];
			
			// port 
			if (!empty ( $this->config ['service']['port'] )) {
				
				$definition['spec']['ports'] = [];
				if (isset($this->deploy_id)) {
					$deploy = AppDeploy::findOrFail($this->deploy_id);
					$this->instance_id = $deploy->app_instance_id;
				} else if (!isset($this->instance_id)) {
					throw new AppInstanceComponentException('Invalid component');
				}
				
				if (isset ( $this->public_ipaddress )) {
					$ipaddress = $this->public_ipaddress;
				} else {
					$private_ipaddress = $this->config ['service'] ['nodes'] [0];
					$instance = AppInstance::find ( $this->instance_id );
					// $env = PaasDeployEnv::where('company_id', $instance->app->company_id)->where('location', $instance->node_group->isp)->first();
					
					/**
					 * private resource pool.binding private ipaddress
					 * public resource pool.binding public ipaddress
					 */
					if ($instance->node_group->cluster) {
						$ipaddress = $private_ipaddress;
					} else {
						$nodes = $instance->node_group->nodes;
						$ipaddress = null;
						foreach ( $nodes as $node ) {
							if ($node->ipaddress == $private_ipaddress) {
								$ipaddress = $node->public_ipaddress;
							}
						}
					}
				}
				
				foreach ( $this->config ['service'] ['port'] as $ports ) {
					
					// public resource pool.automatic port
					if (is_string ( $ports ) && isset ( $this->config ['service'] ['protocol'] )) {
						$target_port = $ports;
						$used = SvcBinding::where ( 'ipaddress', $ipaddress )->get ()->lists ( 'port', 'id' )->toArray ();
						$port = 0;
						for($i = static::MIN_PORT; $i ++; $i < static::MAX_PORT) {
							$rand = rand ( static::MIN_PORT, static::MAX_PORT );
							if (! isset ( $used [$rand] )) {
								$port = $rand;
								break;
							}
						}
						if (! $port) {
							throw new \Exception("没有可分配的端口");
						}
						$ports = [];
						$ports["{$target_port}:{$port}"] = $this->config ['service']['protocol'];
						
					}
					
					foreach ($ports as $port=>$protocol) {
						$net = explode ( ':', $port );
						if (!isset($net[0], $net[1]) || !$protocol) {
							throw new AppInstanceComponentException("Invalid port variable");
						}
						
						$binding = SvcBinding::where('ipaddress', $ipaddress)
							->where('port', $net[1])
							->first();
						if ($binding && 
								($binding->component_name != $this->container ['name'] || $binding->instance_id != $this->instance_id)) 
						{
							throw new AppInstanceComponentException ( "服务设置端口已被占用" );
						} /* else {
							$bindingData = [
									'port' => $net[1],
									'ipaddress' => $definition['spec']['deprecatedPublicIPs'][0],
									'component_name' => $this->container ['name'],
									'instance_id' =>  $this->instance_id
							];
							!$binding && $binding = new SvcBinding($bindingData);
							$binding->save();
						} */
						if ($net[0]) {
							$definition['spec']['ports'][] = [
									'protocol' => $protocol,
									'targetPort' => (int)$net[0],
									'port' => (int)$net[1]
							];
						}
					}
				}
			}
			
			$definition['apiVersion'] = $this->apiVersion;
			
			$definition['metadata']['labels']['name'] = $this->container['name'];
			$definition['metadata']['namespace'] = $this->namespace;
			$definition['metadata']['name'] = $this->container['name'];
		} else if ( empty($this->config['service']['nodes'])  && empty($this->config['service']['port'])) {
			unset($this->svc);
		} else {
			throw new \Exception('服务设置有误，开放端口和节点绑定必须同时设定');
		}
	}
	
	public function resetSvcIpaddress($ipaddress, $public_ipaddress = null) {
		$public_ipaddress && $this->public_ipaddress = $public_ipaddress;
		if (isset($this->config['service']['nodes'])) {
			$this->config['service']['nodes'] = [$ipaddress];
		}
	}
	
	public function resetSvcPort($public_ipaddress) {
		if (isset($this->config['service']['port'])) {
			foreach ( $this->config ['service']['port'] as $index => $ports ) {
				foreach ($ports as $port=>$protocol) {
					$net = explode ( ':', $port );
					$used = SvcBinding::where ( 'ipaddress', $public_ipaddress )->get ()->lists ( 'port', 'id' )->toArray ();
					$port = 0;
					for($i = static::MIN_PORT; $i ++; $i < static::MAX_PORT) {
						$rand = rand ( static::MIN_PORT, static::MAX_PORT );
						if (! isset ( $used [$rand] )) {
							$port = $rand;
							break;
						}
					}
					if (!$port) {
						throw new \Exception("没有可分配的端口");
					}
					$net[1] = $port;
					$this->config['service']['port'][$index] = ["{$net[0]}:{$net[1]}"=>$protocol];
					break; //目前只支持单个ip和端口
				}
				break;
			}
			
		}
	}
	
	/**
	 * decode svc to frontend service section
	 * 
	 * @return NULL
	 */
	public function decodeSvc() {
		if (!$this->svc) {
			return;
		}
		$definition = $this->svc['properties']['definition'];
	
		if (isset($definition['spec']['deprecatedPublicIPs']))
			$this->config['service']['nodes'] = $definition['spec']['deprecatedPublicIPs'];
		
		if (isset($definition['spec']['ports'])) {
			$this->config['service']['port'] = [];
			foreach ($definition['spec']['ports'] as $port) {
				$this->config['service']['port'][] = [($port['targetPort'] .':'. $port['port'])=>$port['protocol'] ];
			}
		}
	}
	
	protected function joinContainer () {
		$labels['internalID'] = $this->internalId;
		$labels['name'] = $this->container['name'];
		$labels['version'] = $this->getVersion();
		
		// metadata
		$this->component['properties']['definition']['metadata']['labels'] = $labels;
		$this->component['properties']['definition']['metadata']['name'] = $labels['name'] . '-' .$labels['internalID'];
		$this->component['properties']['definition']['metadata']['namespace'] = $this->namespace;
		
		// replicas
		$this->component['properties']['definition']['spec']['replicas'] = (int)$this->container['replicas'];
		
		// selector & template
		$this->component['properties']['definition']['spec']['selector'] = $labels;
		$this->component['properties']['definition']['spec']['template']['metadata']['labels'] = $labels;
		$this->component['properties']['definition']['spec']['template']['metadata']['name'] = $labels['name'] . '-' .$labels['internalID'];
		$this->component['properties']['definition']['spec']['template']['metadata']['namespace'] = $this->namespace;
		
		// image & name
		$this->component['properties']['definition']['spec']['template']['spec']['containers'][0] = [
				'image'=>$this->container['image'] .':'.$this->container['version'], 
				'name'=>$this->container['name']
		];
		
		// memory limits
		$this->component['properties']['definition']['spec']['template']['spec']['containers'][0]['resources']['limits']['memory'] = $this->container['memory_max'] * 128 ."Mi";
		$this->component['properties']['definition']['spec']['template']['spec']['containers'][0]['resources']['requests']['memory'] = $this->container['memory_min'] * 128 ."Mi";
		
		// env variable
		if (isset($this->container['env']['variable']) && is_array($this->container['env']['variable'])) {
			foreach ($this->container['env']['variable'] as $var) {
				foreach ($var as $key=>$value) {
					$this->component['properties']['definition']['spec']['template']['spec']['containers'][0]['env'][] = ['name'=>$key, 'value'=>$value];
				}
			}
		} 
	}
	
	public function getImage() {
		return $this->container['image'];
	}
	
	public function setImage($image) {
		$this->container['image'] = $image;
	}
	
	public function getMem() {
		return $this->container['memory_max'] * (int)$this->container['replicas'];
	}
	
	public function getReplicas() {
		return $this->container['replicas'];
	}
	
	public function decodeContainer () {

		$labels = $this->component['properties']['definition']['metadata']['labels'];
		$this->container['name'] = $labels['name'];
        $this->setVersion($labels['version']);
		if (isset($this->component['properties']['definition']['spec']['template']['spec']['containers'][0]['image'])) {
			$image = $this->component['properties']['definition']['spec']['template']['spec']['containers'][0]['image'];
			$image = substr($image, 0, strripos($image, ":"));
			$this->container['image'] = $image;
		}

		$this->container['replicas'] = $this->component['properties']['definition']['spec']['replicas'];
		if (isset($this->component['properties']['definition']['spec']['template']['spec']['containers'][0]['resources']['limits']['memory'])) {
			$this->container['memory_max'] = $this->component['properties']['definition']['spec']['template']['spec']['containers'][0]['resources']['limits']['memory'];
			$this->container['memory_max'] = intval(trim($this->container['memory_max'], 'Mi')) / 128;
		}
		
		if (isset($this->component['properties']['definition']['spec']['template']['spec']['containers'][0]['resources']['requests']['memory'])) {
			$this->container['memory_min'] = $this->component['properties']['definition']['spec']['template']['spec']['containers'][0]['resources']['requests']['memory'];
			$this->container['memory_min'] = intval(trim($this->container['memory_min'], 'Mi')) / 128;
		}
		
		if (isset($this->component['properties']['definition']['spec']['template']['spec']['containers'][0]['env'])) {
			foreach ($this->component['properties']['definition']['spec']['template']['spec']['containers'][0]['env'] as $env) {
				$this->container['env']['variable'][] = [($env['name'])=>$env['value']];
			}
		}
		if (isset($this->component['properties']['definition']['metadata']['labels']['internalID'])) {
			$this->internalId = $this->component['properties']['definition']['metadata']['labels']['internalID'];
		}
	}
	
	protected function joinConfig() {
		$containers = &$this->component['properties']['definition']['spec']['template']['spec']['containers'][0];
		if (!empty ($this->config ['volume']) && is_array ( $this->config ['volume'] )) {
			$this->component['properties']['definition']['spec']['template']['spec']['volumes'] = [];
			$containers ['volumeMounts'] = [];
			foreach ( $this->config ['volume'] as $volumes ) {
				foreach ($volumes as $name=>$volume) {
					$paths = explode ( ':', $volume );
					if (!isset($paths[0], $paths[1]) || !$name) {
						throw new AppInstanceComponentException("Invalid volume variable");
					}
					$containers ['volumeMounts'][] = [ 
							'mountPath' => $paths [1],
							'name' => $name 
					];
					$this->component['properties']['definition']['spec']['template']['spec']['volumes'][] = [
							'hostPath'=>['path'=>$paths[0]],
							'name' => $name
					];
				}
			}
		}
		
		if (!empty($this->config ['rc']['port']) && is_array ( $this->config ['rc']['port'] )) {
			$containers['ports'] = [];
			foreach ( $this->config ['rc']['port'] as $ports ) {
				foreach ($ports as $port=>$protocol) {
					$net = explode ( ':', $port );
					if (!isset($net[0], $net[1]) || !$protocol) {
						throw new AppInstanceComponentException("Invalid port variable");
					}
					$containers['ports'][] = [
							'protocol' => $protocol,
							'hostPort' => (int)$net[1],
							'containerPort' => (int)$net[0]
					];
				}
			}
		}
	}
	

	public function decodeConfig() {
		$containers = $this->component['properties']['definition']['spec']['template']['spec']['containers'][0];
		if (isset($containers['volumeMounts'])) {
			foreach ($containers['volumeMounts'] as $index => $volume) {
				$paths[0] = $this->component['properties']['definition']['spec']['template']['spec']['volumes'][$index]['hostPath']['path'];
				$paths[1] = $volume['mountPath'];
				$this->config['volume'][][$volume['name']] = implode(':', $paths);
			}
		}
		if (isset($containers['ports'])) {
			foreach ($containers['ports'] as $port) {
				$net = [$port['containerPort'], $port['hostPort']];
				$this->config['rc']['port'][] = [(implode(":", $net))=>$port['protocol']];
			}
		}
	}
	
	public function getConfig() {
		return $this->config;
	}
	
	public function setVolume($volume) {
		$this->config['volume'] = $volume;
	}
	
	public function getVolume() {
		if (isset($this->config['volume'])) {
			return $this->config['volume'];
		}
	}
	
	/**
	 * add depends component to depends_on
	 */
	protected function joinDepend($ignore_exist = false) {
		$this->component ['depends_on'] = [ ];
		/**
		 * 判断依赖的组件是否存在
		 * $ignore_exist = true 不判断依赖的组件是否存在（用于实例复制克隆）
		 */
		if (!$ignore_exist) { 
			$depends = [ ];
			if ($this->deploy_id) {
				$components = AppDeployComponent::where ( 'app_deploy_id', $this->deploy_id )->whereIn ( 'name', $this->depends )->get ();
			} elseif ($this->instance_id) {
				$components = AppInstanceComponent::where ( 'app_instance_id', $this->instance_id )->whereIn ( 'name', $this->depends )->get ();
			} else {
				throw new AppInstanceComponentException ( "Depend on error" );
			}
			foreach ( $components as $component ) {
				$ifSvcExist = with ( new AppInstanceComponentProvider () )->ifSvcExist ( $component );
				if ($ifSvcExist) {
					$this->component ['depends_on'] [] = "{$component->name}-svc";
				} else {
					$this->component ['depends_on'] [] = "{$component->name}-rc";
				}
			}
		} else {
			$this->component ['depends_on'] = $this->origin_depends;
		}
	}
	
	public function decodeDepend() {
		$this->origin_depends = $this->component['depends_on'];
		foreach ($this->component['depends_on'] as $depend) {
			$depend_name = substr($depend, 0, strrpos($depend, '-'));
			$this->depends[$depend_name] = $depend_name;
		}
	}
	
	/**
	 * 加入脚本绑定
	 * 
	 */
	protected function joinHook() {
		$containers = &$this->component['properties']['definition']['spec']['template']['spec']['containers'][0];
		if (isset($this->hook['prestop'])) {
			$containers['lifecycle']['preStop'] = $this->getHookActionValue($this->hook['prestop'], 'preStop');
		}
		if (isset($this->hook['poststart']))
			$containers['lifecycle']['postStart'] = $this->getHookActionValue($this->hook['poststart'], 'poststart');
		
		if (isset($this->hook['readiness'])) {
			$containers['readinessProbe'] = $this->getHookActionValue($this->hook['readiness'], 'readinessProbe');
			$containers['readinessProbe']['initialDelaySeconds'] = $this->initialDelaySeconds;
		}
		if (isset($this->hook['alive'])) {
			$containers['livenessProbe'] = $this->getHookActionValue($this->hook['alive'], 'livenessProbe');
			$containers['livenessProbe']['initialDelaySeconds'] = $this->initialDelaySeconds;
		}
		
	}
	
	/**
	 * 解码脚本绑定
	 * 
	 */
	protected function decodeHook() {
		$containers = $this->component['properties']['definition']['spec']['template']['spec']['containers'][0];
		if (isset($containers['lifecycle']['preStop']))
			$this->hook['prestop'] = $this->decodeHookAction($containers['lifecycle']['preStop']);
		
		if (isset($containers['lifecycle']['postStart']))
			$this->hook['poststart'] = $this->decodeHookAction($containers['lifecycle']['postStart']);
		
		if (isset($containers['readinessProbe']))
			$this->hook['readiness'] = $this->decodeHookAction($containers['readinessProbe']);
		
		if (isset($containers['livenessProbe']))
			$this->hook['alive'] = $this->decodeHookAction($containers['livenessProbe']);
	}
	
	public function getHook() {
		return $this->hook;
	}
	
	protected function joinMonitor() {}
	
	public function decodeMonitor() {}
	
	protected function joinLog() {}
	
	public function decodeLog() {}
	
	protected function joinBuild() {
		$this->component['build'] = $this->build;
	}
	
	public function decodeBuild() {
		if (isset($this->component['build']))
			$this->build = $this->component['build'];
	}
	
	public function getBuild() {
		return $this->build;
	}
	
	/**
	 * 检测SVC是否存在
	 * 
	 * @param unknown $component
	 * @return boolean
	 */
	public function ifSvcExist($component) {
		$this->getSections($component);
		if ($this->svc)
			return true;
		else
			return false;
	}
	
	/**
	 * SVC 绑定记录
	 * @param unknown $component
	 */
	public function saveSvcBinding(AppInstanceComponent $component) {
		$svcBindingData = [];
		if (isset($this->svc['properties']['definition']['spec']['deprecatedPublicIPs'][0])) {
			$svcBindingData['ipaddress'] = $this->svc['properties']['definition']['spec']['deprecatedPublicIPs'][0];
			$company_id = $component->instance->app->company_id;
			$node_group = $component->instance->node_group()->first();
			// $env = PaasDeployEnv::where('location', $node_group->isp)->where('company_id',$company_id)->first();
			/**
			 * 公开版的绑定公网ip
			 */
			/*if (!$env) {
				$nodes = $node_group->nodes()->get()->toArray();
				foreach ($nodes as $node) {
					if ($node['ipaddress'] == $svcBindingData['ipaddress']) {
						logger($node);
						$svcBindingData ['ipaddress'] = $node['public_ipaddress'];
						break;
					}
				}
			}*/
		} else {
			return;
		}
		if (isset($this->svc['properties']['definition']['spec']['ports'])) {
			foreach ($this->svc['properties'] ['definition'] ['spec'] ['ports'] as $port ) {
				$svcBindingData ['port'] = $port ['port'];
			}
		} else {
			return;
		}
		$svcBindingData ['instance_id'] = $component->app_instance_id;
		$svcBindingData ['component_name'] = $component->name;
		$svcBinding = SvcBinding::where($svcBindingData)->firstOrNew($svcBindingData);
		$svcBinding->save();
	}
	
	/**
	 * 移除节点端口和组件的绑定。
	 * 目前组件只支持单个端口的绑定，以后需要改动
	 * @param AppInstanceComponent $component
	 * @param unknown $service
	 */
	public function removeSvcBinding(AppInstanceComponent $component, $service=null) {
		
		if (!isset($service['port']) && isset($this->config['service']['port'])) {
			$service['port'] = $this->config['service']['port'];
		} 
		
		if (!isset($service['nodes']) && isset($this->config['service']['nodes'])) {
			$service['nodes'] = $this->config['service']['nodes'];
		}
		
		if (!isset($service['port']) && !isset($service['nodes'])) {
			return;
		}
		
		$svcBindingData ['instance_id'] = $component->app_instance_id;
		$svcBindingData ['component_name'] = $component->name;
		$node_group = $component->instance->node_group()->first();
		$company_id = $component->instance->app->company_id;
		$env = PaasDeployEnv::where('location', $node_group->isp)->where('company_id', $company_id)->first();
		
		/**
		 * 公开版的绑定公网ip
		 */
		if (!$env) {
			$nodes = $node_group->nodes()->get()->toArray();
			foreach ($nodes as $node) {
				if ($node['ipaddress'] == $service ['nodes']) {
					$svcBindingData ['ipaddress'] = $node['public_ipaddress'];
					break;
				}
			}
		} else {
			$svcBindingData ['ipaddress'] = $service ['nodes'];
		}
		
		foreach ($service['port'] as $ports) { //
			foreach ($ports as $port=>$protocol) {
				$net = explode ( ':', $port );
				$svcBindingData ['port'] = $net [1];
			}
		}
		
		$bind = SvcBinding::where($svcBindingData)->first();
        if ($bind)
            $bind->forceDelete();
	}
	
	/**
	 * decode definition
	 * 
	 * @param AppInstanceComponent||AppDeployInstanceComponent $component
	 */
	public function decodeDefinition($component) {
		$definition = json_decode ( $component->definition, true );
		$this->component = $definition [$component ['name'] . '-rc'];
		isset ( $definition [$component ['name'] . '-svc'] ) && $this->svc = $definition [$component ['name'] . '-svc'];
		
		$this->kubernetesEndpoint = $this->component ['properties'] ['kubernetes_endpoint'];
		$this->apiVersion = $this->component ['properties'] ['apiversion'];
		$this->namespace = $this->component ['properties'] ['namespace'];
		$nodeSelector = $this->component ['properties'] ['definition'] ['spec'] ['template'] ['spec'] ['nodeSelector'];
		foreach ( $nodeSelector as $selector => $y ) {
			if (strpos ( $selector, "idevops.env" ) >= 0) {
				$arr = explode ( '.', $selector );
				isset ( $arr [2] ) && $this->group = $arr [2];
				// isset ( $arr [2] ) && $this->app_id = $arr [2];
			}
		}
		$this->decodeConfig ();
		$this->decodeContainer ();
		$this->decodeDepend ();
		$this->decodeHook ();
		$this->decodeLog ();
		$this->decodeMonitor ();
		$this->decodeSvc ();
		$this->decodeBuild ();
	}
	
	public function getSections($component) {
		$this->decodeDefinition($component);
		return [ 
				'container' => $this->container,
				'config' => $this->config,
				'log' => $this->log,
				'monitor' => $this->monitor,
				'depends' => $this->depends,
				'hook' => $this->hook,
				'build' => $this->build 
		];
	}
	
	/**
	 * 
	 * get hook action value
	 * 
	 * @param string $action
	 * @return string|multitype:unknown mixed |unknown
	 */
	protected function getHookActionValue($action, $hookType) {
		if (! is_array ( $action )) {
			return null;
		}
		foreach ( $action as $type => $value) {
			switch ($type) {
				case 'httpget' :
					$validator = validator(["$hookType"=>$value], ["$hookType"=>'url']);
					if ($validator->fails()) {
						throw new ValidateException($validator->errors());
					}
					$link = parse_url ( $value );
					$value = ['httpGet'=>[ 
									'host' => $link ['host'],
									'scheme' => strtoupper ( (isset ( $link ['scheme'] ) ? $link ['scheme'] : 'HTTP') )
							] 
					];
					if ($link['host'] == '127.0.0.1' || $link['host'] == 'localhost') {
						unset($value['httpGet']['host']);
					}
					! empty ( $link ['path'] ) && $value ['httpGet']['path'] = $link ['path'];
					$value ['httpGet']['port'] = ! empty ( $link ['port'] ) ? $link ['port'] : 80;
					return $value;
				case 'exec' :
					return 
						[
							'exec' => [
								'command'=>[ 
								"bash",
								"-c",
								$value 
								]
							]
						];
				case 'socket' :
					return $value; // port 1~65535
			}
		}
	}
	
	public function decodeHookAction($action) {
		if (! is_array ( $action )) {
			return null;
		}
		foreach ($action as $type=>$hook) {
			
			switch ($type) {
				case 'httpGet' :
					empty($hook ['host']) && $hook ['host'] = 'localhost';
					$value = $hook ['scheme'] . "://" . $hook ['host'] . ":" . $hook ['port'];
					isset($hook['path']) && $value .= $hook ['path'];
					return [ 
							'httpget' => $value
					];
				case 'exec' :
					return [ 
							'exec' => $hook['command'][2] 
					];
				case 'socket' :
					return [ 
						'socket' => $hook 
				];
			}
		}
	}
	
}


