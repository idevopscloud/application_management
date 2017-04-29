<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class App extends Model
{
	use SoftDeletes;
	
	protected $table = 'apps';
	
	public $incrementing = false;

	protected $dates = ['deleted_at'];
	
	protected $fillable = [
		'id', 'name', 'code_name', 'description', 'icon', 'master_user_id', 'master_user_name', 'role_group_id', 'node_group_id', 'company_id'
	];
	
	public function nodeGroups()
	{
		return $this->belongsToMany('App\AppNodeGroup', 'app_app_node_group_rel');
	}
	
	/**
	 * Get the nodes for the app.
	 */
	/*public function nodes()
	{
		return $this->hasMany('App\AppNode');
	}*/
	
	public function instances()
	{
		return $this->hasMany('App\AppInstance');
	}
}
