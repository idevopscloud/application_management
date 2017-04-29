<?php namespace App\Providers\Application;

use App\AppDeployComponent;
use App\PaasDeployEnv;
use App\AppDeploy;
use App\Exceptions\AppDeployComponentException;
use App\SvcBinding;
use App\Providers\Api\ApiProvider;
use Illuminate\Support\Facades\Input;
class AppDeployComponentProvider {
	
	protected $component;
	
	protected $rc_container = [];
	protected $config = [];
	protected $rc_depends = [];
	protected $rc_hook = [];
	protected $rc_monitor = [];
	protected $rc_log = [];
	
	protected $app_id;
	
	protected $rc = [ 
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
	
	protected $rc_internalId;
	
	protected $initialDelaySeconds = 3;
	
	protected $kubernetesEndpoint;
	
	protected $rc_version;
	
	protected $targetPort = [];
	
	public function __construct(AppDeployComponent $component = null) {
		if ( $component) {
			$this->setComponent($component);
		}
	}
	
	public function setComponent(AppDeployComponent $component) {
		$this->component = $component;
	}
	
	public function setSection($key, $value) {
		isset($this->$key) && $this->$key = $value;
	}
	
	public function setVersion($version) {
		$this->rc_version = $version;
	}
	
	public function getVersion() {
		return $this->rc_version;
	}
	
	public function setAppId($app_id) {
		$this->app_id = $app_id;
	}
	
	public function setNameSpace($namespace) {
		$this->namespace = $namespace;
	}
	
	public function getNameSpace() {
		return $this->namespace;
	}
	
	public function setKubernetesEndpoint($endpoint) {
		$this->kubernetesEndpoint = $endpoint;
	}
	
	public function setGroup($group) {
		$this->group = $group;
	}
	
	public function genInternalId() {
		$this->rc_internalId = uniqid();
	}
	
	public function getTargetPort() {
		return $this->targetPort;
	}
	
	public static function cloneOne($from_com_id, $to_deploy_id) {
	
		$from_com = AppDeployComponent::with('deploy')->findOrFail($from_com_id);
		
		$deploy = AppDeploy::with('instance')->findOrFail($to_deploy_id);
		$cluster = $deploy->instance->node_group->cluster;
		// $env = PaasDeployEnv::where('location', $isp)->firstOrFail();
		
		$to_provider = with(new AppInstanceComponentProvider());
		$provider = new AppInstanceComponentProvider();
		$sections = $provider->getSections($from_com);
		
		$provider->setKubernetesEndpoint($cluster->k8s_endpoint);
		$provider->setNameSpace($deploy->instance->name);
		// $provider->setAppId($deploy->instance->app_id);

		$provider->setGroup($deploy->instance->node_group->id);
		if ($from_com->deploy->instance->node_group->cluster->registry_id != $deploy->instance->node_group->cluster->registry_id) {
			$build = $provider->getBuild();
			$build['sync_from'] = $provider->getImage() . ':' . $provider->getVersion();
			$provider->setSection('build', $build);

			$registry = with(new ApiProvider)->getRegistry(['token' => Input::get('token'), 'id' => $cluster->registry_id]);
			$image = $provider->getImage();
			$blade = parse_url($image);
			$image = $registry['host'] . $blade['path'];
			$provider->setImage($image);
		}

		/*if ($cluster->company_id) {
			$provider->resetSvcIpaddress ( '0.0.0.0' );
		} else {*/
			$nodes = $deploy->instance->node_group->nodes()->get()->toArray();
			$rand = array_rand($nodes);
			$node = $nodes[$rand];
			$provider->resetSvcIpaddress ( $node['ipaddress'], $node['public_ipaddress'] );
			$provider->resetSvcPort($node['public_ipaddress']);
		// }
		$provider->genInternalId();
		$provider->setDeployId($to_deploy_id);
		
		$comData = $from_com->toArray();
		unset($comData['id']);
		$new = new AppDeployComponent($comData);
		$new->app_deploy_id = $to_deploy_id;
		$new->definition = json_encode($provider->getComponentJson());
		$new->save();
	}
	
	/**
	 * release depend relations
	 * 
	 * @param AppDeployComponent $component
	 */
	public static function releaseDepends(AppDeployComponent $component) {
		
		$components = AppDeployComponent::where('app_deploy_id', $component->app_deploy_id)->where('id', '<>', $component->id)->get();
		foreach ($components as $other_component) {
			$provider = new AppDeployComponentProvider($other_component);
			$provider->decodeDefinition();
			$depends = $provider->getDepend();
			if ( in_array($component->name, $depends) ) {
				array_pull($depends, $component->name);
				$provider->setSection('rc_depends', $depends);
				$definition = $provider->getDefinition();
				if ($other_component->definition != $definition) {
					$provider->genInternalId();
				}
				$other_component->definition = $provider->getDefinition();
				$other_component->save();
			}
		}
	}
	
	protected function appendSvc() {
		if (! empty ( $this->config ['service'] ['nodes'] ) && ! empty ( $this->config ['service'] ['port'] ) && is_array ( $this->config ['service'] ['nodes'] ) && is_array ( $this->config ['service'] ['port'] )) {
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
			
			$definition = &$this->svc ['properties'] ['definition'];
			$definition ['spec'] ['selector'] ['name'] = $this->rc_container ['name'];
			
			// ipaddress bind
			$definition ['spec'] ['deprecatedPublicIPs'] = $this->config ['service'] ['nodes'];
			
			// port
			if (! empty ( $this->config ['service'] ['port'] )) {
				$definition ['spec'] ['ports'] = [ ];
				foreach ( $this->config ['service'] ['port'] as $ports ) {
					foreach ( $ports as $port => $protocol ) {
						$net = explode ( ':', $port );
						if (! isset ( $net [0], $net [1] ) || ! $protocol) {
							throw new AppDeployComponentException ( "Invalid port variable" );
						}
						if (isset ( $this->component->app_deploy_id )) {
							$deploy = AppDeploy::findOrFail ( $this->component->app_deploy_id );
							$instance_id = $deploy->app_instance_id;
						} else  {
							throw new AppDeployComponentException ( 'Invalid component' );
						}
						$binding = SvcBinding::where ( 'ipaddress', $definition ['spec'] ['deprecatedPublicIPs'] [0] )->where ( 'port', $net [1] )->first ();
						if ($binding && ($binding->component_name != $this->rc_container ['name'] || $binding->instance_id != $instance_id)) {
							throw new AppDeployComponentException ( "服务设置端口已被占用" );
						}/*  else {
							$bindingData = [ 
									'port' => $net [1],
									'ipaddress' => $definition ['spec'] ['deprecatedPublicIPs'] [0],
									'component_name' => $this->rc_container ['name'],
									'instance_id' => $instance_id 
							];
							! $binding && $binding = new SvcBinding ( $bindingData );
							$binding->save ();
						} */
						if ($net [0]) {
							$definition ['spec'] ['ports'] [] = [ 
									'protocol' => $protocol,
									'targetPort' => ( int ) $net [0],
									'port' => ( int ) $net [1] 
							];
						}
					}
				}
			}
			
			$definition ['apiVersion'] = $this->apiVersion;
			
			$definition ['metadata'] ['labels'] ['name'] = $this->rc_container ['name'];
			$definition ['metadata'] ['namespace'] = $this->namespace;
			$definition ['metadata'] ['name'] = $this->rc_container ['name'];
		} else if (empty ( $this->config ['service'] ['nodes'] ) && empty ( $this->config ['service'] ['port'] )) {
			$this->svc = null;
		} else {
			throw new \Exception ( '服务设置有误，开放端口和节点绑定必须同时设定' );
		}
	}
	
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
				$this->targetPort[] = $port['targetPort'];
				$this->config['service']['port'][] = [($port['targetPort'] .':'. $port['port'])=>$port['protocol'] ];
			}
		}
	}
	
	public function getSvc() {
		return $this->svc;
	}
	
	public function decodeRc() {
		
		$this->decodeConfig ();
		
		$this->decodeContainer ();
		
		$this->decodeDepend ();
		
		$this->decodeHook ();
		
		$this->decodeLog ();
		
		$this->decodeMonitor ();
	}
	
	protected function appendContainer () {
		!$this->rc_version && $this->setVersion($this->rc_container['version']);
		$labels['internalID'] = $this->rc_internalId;
		$labels['name'] = $this->rc_container['name'];
		$labels['version'] = $this->rc_version;
	
		// metadata
		$this->rc['properties']['definition']['metadata']['labels'] = $labels;
		$this->rc['properties']['definition']['metadata']['name'] = $labels['name'] . '-' .$labels['internalID'];
		$this->rc['properties']['definition']['metadata']['namespace'] = $this->namespace;
	
		// replicas
		$this->rc['properties']['definition']['spec']['replicas'] = (int)$this->rc_container['replicas'];
	
		// selector & template
		$this->rc['properties']['definition']['spec']['selector'] = $labels;
		$this->rc['properties']['definition']['spec']['template']['metadata']['labels'] = $labels;
		$this->rc['properties']['definition']['spec']['template']['metadata']['name'] = $labels['name'] . '-' .$labels['internalID'];
		$this->rc['properties']['definition']['spec']['template']['metadata']['namespace'] = $this->namespace;
	
		// image & name
		$this->rc['properties']['definition']['spec']['template']['spec']['containers'][0] = [
				'image'=>$this->rc_container['image'] .':'.$this->rc_container['version'],
				'name'=>$this->rc_container['name']
		];
	
		// memory limits
		$this->rc['properties']['definition']['spec']['template']['spec']['containers'][0]['resources']['limits']['memory'] = $this->rc_container['memory_max'] * 128 ."Mi";
		$this->rc['properties']['definition']['spec']['template']['spec']['containers'][0]['resources']['requests']['memory'] = $this->rc_container['memory_min'] * 128 ."Mi";
	
		// env variable
		if (isset($this->rc_container['env']['variable']) && is_array($this->rc_container['env']['variable'])) {
			foreach ($this->rc_container['env']['variable'] as $var) {
				foreach ($var as $key=>$value) {
					$this->rc['properties']['definition']['spec']['template']['spec']['containers'][0]['env'][] = ['name'=>$key, 'value'=>$value];
				}
			}
		}
	}
	
	protected function decodeContainer () {
	
		$labels = $this->rc['properties']['definition']['metadata']['labels'];
		$this->rc_container['name'] = $labels['name'];
		$this->rc_container['version'] = $labels['version'];
		if (isset($this->rc['properties']['definition']['spec']['template']['spec']['containers'][0]['image'])) {
			$image = $this->rc['properties']['definition']['spec']['template']['spec']['containers'][0]['image'];
			$image = substr($image, 0, strripos($image, ":"));
			$this->rc_container['image'] = $image;
		}
	
		$this->rc_container['replicas'] = $this->rc['properties']['definition']['spec']['replicas'];
		if (isset($this->rc['properties']['definition']['spec']['template']['spec']['containers'][0]['resources']['limits']['memory'])) {
			$this->rc_container['memory_max'] = $this->rc['properties']['definition']['spec']['template']['spec']['containers'][0]['resources']['limits']['memory'];
			$this->rc_container['memory_max'] = intval(trim($this->rc_container['memory_max'], 'Mi')) / 128;
		}
	
		if (isset($this->rc['properties']['definition']['spec']['template']['spec']['containers'][0]['resources']['requests']['memory'])) {
			$this->rc_container['memory_min'] = $this->rc['properties']['definition']['spec']['template']['spec']['containers'][0]['resources']['requests']['memory'];
			$this->rc_container['memory_min'] = intval(trim($this->rc_container['memory_min'], 'Mi')) / 128;
		}
	
		if (isset($this->rc['properties']['definition']['spec']['template']['spec']['containers'][0]['env'])) {
			foreach ($this->rc['properties']['definition']['spec']['template']['spec']['containers'][0]['env'] as $env) {
				$this->rc_container['env']['variable'][] = [($env['name'])=>$env['value']];
			}
		}
		if (isset($this->rc['properties']['definition']['metadata']['labels']['internalID'])) {
			$this->rc_internalId = $this->rc['properties']['definition']['metadata']['labels']['internalID'];
		}
	}
	
	public function getContainer () {
		return $this->rc_container;
	}
	
	protected function appendConfig() {
		$containers = &$this->rc['properties']['definition']['spec']['template']['spec']['containers'][0];
		if (!empty ($this->config ['volume']) && is_array ( $this->config ['volume'] )) {
			$this->rc['properties']['definition']['spec']['template']['spec']['volumes'] = [];
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
					$this->rc['properties']['definition']['spec']['template']['spec']['volumes'][] = [
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
	
	protected function decodeConfig() {
		$containers = $this->rc['properties']['definition']['spec']['template']['spec']['containers'][0];
		if (isset($containers['volumeMounts'])) {
			foreach ($containers['volumeMounts'] as $index => $volume) {
				$paths[0] = $this->rc['properties']['definition']['spec']['template']['spec']['volumes'][$index]['hostPath']['path'];
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
	
	public function getConfig () {
		return $this->config;
	}
	
	protected function appendDepend() {
		$this->rc ['depends_on'] = [ ];
		if ($this->rc_depends) {
			$depends = [ ];
			if ($this->component->app_deploy_id) {
				$components = AppDeployComponent::where ( 'app_deploy_id', $this->component->app_deploy_id )->whereIn ( 'name', $this->rc_depends )->get ();
			} else {
				throw new AppDeployComponentException ( "Depend on error" );
			}
			foreach ( $components as $component ) {
				$provider = new AppDeployComponentProvider($component);
				$provider->decodeDefinition();
				if ($provider->getSvc()) {
					$this->rc ['depends_on'] [] = "{$component->name}-svc";
				} else {
					$this->rc ['depends_on'] [] = "{$component->name}-rc";
				}
			}
		}
	}
	
	protected function decodeDepend() {
		foreach ( $this->rc ['depends_on'] as $depend ) {
			$this->rc_depends [] = substr ( $depend, 0, strrpos($depend, '-'));
		}
		return $this->rc_depends;
	}
	
	public function getDepend() {
		return $this->rc_depends;
	}
	
	protected function appendHook() {
		$containers = &$this->rc ['properties'] ['definition'] ['spec'] ['template'] ['spec'] ['containers'] [0];
		if (isset ( $this->rc_hook ['prestop'] )) {
			$containers ['lifecycle'] ['preStop'] = $this->getHookActionValue ( $this->rc_hook ['prestop'] );
		}
		if (isset ( $this->rc_hook ['poststart'] ))
			$containers ['lifecycle'] ['postStart'] = $this->getHookActionValue ( $this->rc_hook ['poststart'] );
		
		if (isset ( $this->rc_hook ['readiness'] )) {
			$containers ['readinessProbe'] = $this->getHookActionValue ( $this->rc_hook ['readiness'] );
			$containers ['readinessProbe'] ['initialDelaySeconds'] = $this->initialDelaySeconds;
		}
		if (isset ( $this->rc_hook ['alive'] )) {
			$containers ['livenessProbe'] = $this->getHookActionValue($this->rc_hook['alive']);
			$containers['livenessProbe']['initialDelaySeconds'] = $this->initialDelaySeconds;
		}
	}
	
	protected function decodeHook() {
		$containers = $this->rc ['properties'] ['definition'] ['spec'] ['template'] ['spec'] ['containers'] [0];
		if (isset ( $containers ['lifecycle'] ['preStop'] ))
			$this->rc_hook ['prestop'] = $this->decodeHookAction ( $containers ['lifecycle'] ['preStop'] );
		
		if (isset ( $containers ['lifecycle'] ['postStart'] ))
			$this->rc_hook ['poststart'] = $this->decodeHookAction ( $containers ['lifecycle'] ['postStart'] );
		
		if (isset ( $containers ['readinessProbe'] ))
			$this->rc_hook ['readiness'] = $this->decodeHookAction ( $containers ['readinessProbe'] );
		
		if (isset ( $containers ['livenessProbe'] ))
			$this->rc_hook ['alive'] = $this->decodeHookAction ( $containers ['livenessProbe'] );
		
	}
	
	public function getHook() {
		return $this->rc_hook;
	}
	
	protected function appendMonitor() {}
	
	public function decodeMonitor() {}
	
	protected function appendLog() {}
	
	protected function decodeLog() {}
	
	public function getDefinition() {
		if (! $this->rc_internalId) {
			$this->genInternalId ();
		}
		$this->rc ['properties'] ['kubernetes_endpoint'] = $this->kubernetesEndpoint;
		$this->rc ['properties'] ['apiversion'] = $this->apiVersion;
		$this->rc ['properties'] ['namespace'] = $this->namespace;
		$this->rc ['properties'] ['definition'] ['apiVersion'] = $this->apiVersion;
		$this->rc ['properties'] ['definition'] ['kind'] = "ReplicationController";
		
		$this->appendRc ();
		if ($this->group)
			$this->rc ['properties'] ['definition'] ['spec'] ['template'] ['spec'] ['nodeSelector'] = [ 
					"idevops.env.{$this->group}" => 'Y' 
			];
		
		$this->appendSvc ();
		
		$data = [ 
				$this->rc_container ['name'] . '-rc' => $this->rc 
		];
		if ($this->svc) {
			$this->svc ['depends_on'] = [ 
					$this->rc_container ['name'] . '-rc' 
			];
			$data [$this->rc_container ['name'] . '-svc'] = $this->svc;
		}
		return json_encode ( $data );
	}
	
	protected function appendRc() {
		
		$this->appendContainer ();
		
		$this->appendConfig ();
		
		$this->appendDepend ();
		
		$this->appendHook ();
		
		$this->appendMonitor ();
		
		$this->appendLog ();
	}
	
	public function decodeDefinition() {
		$definition = json_decode ( $this->component->definition, true );
		$this->rc = $definition [$this->component ['name'] . '-rc'];
		isset ( $definition [$this->component ['name'] . '-svc'] ) && $this->svc = $definition [$this->component ['name'] . '-svc'];
	
		$this->kubernetesEndpoint = $this->rc ['properties'] ['kubernetes_endpoint'];
		$this->apiVersion = $this->rc ['properties'] ['apiversion'];
		$this->namespace = $this->rc ['properties'] ['namespace'];
		$nodeSelector = $this->rc ['properties'] ['definition'] ['spec'] ['template'] ['spec'] ['nodeSelector'];
		foreach ( $nodeSelector as $selector => $y ) {
			if (strpos ( $selector, "idevops.env" ) >= 0) {
				$arr = explode ( '.', $selector );
	
				// isset ( $arr [3] ) && $this->group = $arr [3];
				isset ( $arr [2] ) && $this->group = $arr [2];
			}
		}
		$this->decodeRc ();
		$this->decodeSvc ();
	}
	
	protected function getHookActionValue($action) {
	if (! is_array ( $action )) {
			return null;
		}
		foreach ( $action as $type => $value ) {
			switch ($type) {
				case 'httpget' :
					$link = parse_url ( $value );
					$value = ['httpGet'=>[ 
							'host' => $link ['host'],
							'scheme' => strtoupper($link ['scheme'])
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


