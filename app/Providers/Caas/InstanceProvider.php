<?php namespace App\Providers\Caas;
use Illuminate\Http\Request;
use App\SvcBinding;
use App\PaasDeployEnv;
use App\Providers\Env\EnvProvider;
use App\Repo;
class InstanceProvider {
	
	private $name = '';
	private $image = '';
	private $version = '';
	private $requests_memory = '';
	private $limits_memory = '';
	private $replicas = 1;
	private $namespace = '';
	private $targetPort = null;
	private $port = null;
	private $public_ipaddress = null;
	private $externalIPs = [];
	private $ports = [];
	private $env = [];
	const CAAS_PREFIX = 'caas-';
	const MAX_PORT = 42000;
	const MIN_PORT = 40000;
	
	public function __construct($namespace, PaasDeployEnv $paas_env) {
		$this->namespace = $namespace;
		$this->paas_env = $paas_env;
	}
	
	private function setExternals(PaasDeployEnv $paas_env) {
		$node_data = with(new EnvProvider())->getNodes($paas_env);
		$nodes = [];
		if (!empty($node_data['items'])) {
			$key = array_rand($node_data['items'], 1);
			$public_ipaddress = $node_data['items'][$key]['public_address'];
			$private_ipaddress = $node_data['items'][$key]['IP'];
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
			$this->public_ipaddress = $public_ipaddress;
			$this->externalIPs = [$private_ipaddress];
			$this->port = $port;
			$this->ports = [
					[
							'targetPort' => $this->targetPort,
							'protocol' => 'TCP',
							'port' => $port 
					]
		];
		}
	}
	
	public function saveSvcBinding() {
		if (!$this->public_ipaddress){
			return;
		}
		$bindingData = [
				'ipaddress' => $this->public_ipaddress,
				'port' => $this->port,
				'instance_id' => time(),
				'component_name' => $this->name
		];
		$binding = new SvcBinding($bindingData);
		$binding->save();
	}
	
	public static function removeSvcBinding($ipaddress, $port, $component_name) {
		$binding = SvcBinding::where('ipaddress', $ipaddress)->where('port', $port)->where('component_name', $component_name)->first();
		$binding && $binding->forceDelete();
	}
	
	public function setAttributes($attribtues) {
		$vars = get_class_vars(__CLASS__);
		foreach ($attribtues as $key=>$val) {
			if (!array_key_exists($key, $vars))
				continue;
			$this->$key = $val;
		}
	}
	
	public function getDefinition() {
		$this->beforeDefinition();
		$attributes = get_object_vars($this);
		extract($attributes);
		$definition = include(__DIR__.'/template.php');
		$definition = $this->afterDefinition($definition);
		return $definition;
	}
	
	protected function beforeDefinition() {
		$this->limits_memory = $this->requests_memory = ((int)$this->requests_memory * 128)."Mi";
		if ($this->targetPort) {
			$this->targetPort = (int)$this->targetPort;
			$this->setExternals($this->paas_env);
		} 
		$repo = Repo::findOrFail($this->image);
		$this->image = $repo->full_name;
		$this->namespace = static::CAAS_PREFIX.$this->namespace;
	}
	
	protected function afterDefinition($definition) {
		if (!$this->targetPort && isset($definition['svc_template'])) {
			unset($definition['svc_template']);
		}
		return $definition;
	}
	
	public function getNamespace() {
		return $this->namespace;
	}
	
}