<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AppNodeGroup extends Model
{
	protected $table = 'app_node_groups';

	public $incrementing = false;
	
	protected $fillable = [
			'id', 'app_id', 'name', 'isp', 'env_category'
	];
	
	public function nodes()
	{
		return $this->hasMany('App\AppNode', 'group_id');
	}
	
	public function apps() 
	{
		// return $this->hasMany('App\AppAppNodeGroupRel', 'app_node_group_id');
		return $this->belongsToMany('App\App', 'app_app_node_group_rel');
	}

	public function instances()
	{
		return $this->hasMany('App\AppInstance', 'node_group_id');
	}
	
	public function cluster() 
	{
		return $this->belongsTo('App\PaasDeployEnv', 'isp', 'location');
	}

	public function teams() 
	{
		return $this->hasMany('App\AppNodeGroupTeamRel', 'group_id');
	}
}
