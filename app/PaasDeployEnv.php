<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class PaasDeployEnv extends Model
{
	protected $fillable = [
		'company_id', 'name', 'paas_api_url', 'k8s_endpoint', 'location', 'registry_id', 'registry_name'
	];
	protected $table = 'paas_deploy_env';
	
}
