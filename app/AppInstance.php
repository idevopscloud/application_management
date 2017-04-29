<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppInstance extends Model
{
	use SoftDeletes;
	
	protected $dates = ['deleted_at'];
	
	protected $fillable = [
			'id', 'name', 'app_id', 'env_category', 'node_group_id', 'master_user_id', 'master_user_name'
	];
	
	public function components()
	{
		return $this->hasMany('App\AppInstanceComponent');
	}
	
	public function deploys()
	{
		return $this->hasMany('App\AppDeploy');
	}
	
	public function app() {
		return $this->belongsTo('App\App');
	}
	
	public function node_group() {
		return $this->belongsTo('App\AppNodeGroup');
	}
}
