<?php

use Illuminate\Database\Seeder;
use App\PaasDeployEnv;

class DeployEnvTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
    	if (PaasDeployEnv::where('name', '公共集群')->exists() == false) {
    		DB::table('paas_deploy_env')->insert([
	    		'name' => '公共集群',
	    		'paas_api_url' => env('PAAS_API_URL'),
	    		'k8s_endpoint' => env('K8S_END_POINT'),
	    		'location' => '公共集群',
	    		'created_at' => date('Y-m-d H:i:s'),
	    		'updated_at' => date('Y-m-d H:i:s'),
    		]);
    	}
    }
}
