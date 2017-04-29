<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AppNodeGroupTeamRel extends Model
{
    protected $table = 'app_node_groups_team_rel';
	
	protected $fillable = [
		'group_id', 'team_id', 'team_name'
	];

	public function ngs() {
		return $this->hasOne('App\AppNodeGroup', 'id', 'group_id');
	}
}
