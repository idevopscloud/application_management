<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\AppDeploy;
use App\PaasDeployEnv;
use App\Providers\Application\AppInstanceProvider;
use Illuminate\Support\Facades\DB;

class GetDeployStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'getDeployStatus';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'get deploy status from paas';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
		parent::__construct ();
	}
	
	/**
	 * Execute the console command.
	 *
	 * @return mixed
	 */
	public function handle() {
		$time_limit = time () - 86400;
		/* $deploys = AppDeploy::with ( 'instance' )
			->where ( 'is_deploy', 1 )
			->where ( 'status', 0 )
			->where ( 'created_at', '>', date ( 'Y-m-d H:i:s', $time_limit ) )
		->get (); */
		$sub = AppDeploy::where ( 'is_deploy', 1 )
			->where ( 'status', 0 )
			->where ( 'created_at', '>', date ( 'Y-m-d H:i:s', $time_limit ) )
			->orderBy('created_at', 'desc'); 
		
		$deploys = AppDeploy::with('instance')->from( DB::raw("({$sub->toSql()}) as idevops_app_deploys") )
			->mergeBindings($sub->getQuery()) // you need to get underlying Query Builder
			->groupBy('app_instance_id')
		->get();
		foreach ( $deploys as $deploy ) {
			try {
				if ($deploy->instance && $deploy->status != 1) {
					$node_group = $deploy->instance->node_group ()->first ();
					$this->comment ( json_encode ( $node_group ) );
					$location = $node_group ['isp'];
					$env = PaasDeployEnv::where ( 'location', $location )->firstOrFail ();
					$status = do_request_paas ( "{$env->paas_api_url}/applications/{$deploy->instance->name}?summary=y", 'GET',[],null,$env->api_key  );
					$this->comment ( json_encode ( $status ) );
					if (isset ( $status ['stack_info'] ['stack_status'] ) 
							&& strpos ( $status ['stack_info'] ['stack_status'], 'COMPLETE' ) > 0 
							&& $status ['stack_info'] ['stack_status'] != 'ROLLBACK_COMPLETE') 
					{
						$deploy->status = 1;
						DB::beginTransaction ();
						try {
							$deploy->save ();
							// with (new AppInstanceProvider)->syncFromDeploy($deploy->id, $deploy->instance->id);
						} catch ( \Exception $e ) {
							DB::rollBack ();
							throw $e;
						} finally {
							DB::Commit ();
						}
					}
				}
			} catch ( \Exception $e) {
                \Log::error($e);    
            }
        }
        $this->comment("request paas url");
    }
}
