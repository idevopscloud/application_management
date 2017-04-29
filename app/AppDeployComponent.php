<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppDeployComponent extends Model
{
	use SoftDeletes;
	
	protected $table = 'app_deploy_components';
	
	public $incrementing = false;
	
	protected $dates = ['deleted_at'];
	
	protected $fillable = [
			'id', 'name', 'version', 'app_deploy_id', 'definition'
	];
	
	public function deploy()
	{
		return $this->belongsTo('App\AppDeploy', 'app_deploy_id');
	}
}
