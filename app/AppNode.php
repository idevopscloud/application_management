<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class AppNode extends Model
{
	protected $table = 'app_nodes';
	
	protected $fillable = [
			'id'/*, 'app_id'*/, 'group_id', 'ipaddress', 'isp', 'public_ipaddress'
	];
	
	public function group() {
		return $this->belongsTo('App\AppNodeGroup');
	}
}
