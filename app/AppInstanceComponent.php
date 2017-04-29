<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class AppInstanceComponent extends Model
{
	use SoftDeletes;
	
	protected $table = 'app_instance_components';
	
	protected $dates = ['deleted_at'];
	
	protected $fillable = [
			'id', 'name', 'app_instance_id', 'definition', 'version'
	];
	
	public function instance()
	{
		return $this->belongsTo('App\AppInstance', 'app_instance_id');
	}
}
