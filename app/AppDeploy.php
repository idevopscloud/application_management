<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppDeploy extends Model
{
	use SoftDeletes;
	
	protected $table = 'app_deploys';
	
	protected $dates = ['deleted_at'];
	
	protected $fillable = [
			'id', 'name', 'app_instance_id', 'user_id', 'user_name', 'status', 'is_deploy'
	];
	
	public function components()
	{
		return $this->hasMany('App\AppDeployComponent');
	}
	
	public function instance() {
		return $this->belongsTo('App\AppInstance', 'app_instance_id');
	}
	
}
