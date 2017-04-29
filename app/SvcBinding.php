<?php

namespace App;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Model;

class SvcBinding extends Model
{
	use SoftDeletes;
	
	protected $table = 'svc_bindings';
	
	protected $dates = ['deleted_at'];
	
	protected $fillable = [
			'id', 'ipaddress', 'port', 'component_name', 'instance_id'
	];
}
