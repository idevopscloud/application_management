<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class Repo extends Model
{
	protected $table = 'repos';
	
	public $incrementing = false;
	
	protected $dates = ['deleted_at'];
	
	protected $fillable = [
	];
	
	public function tags()
	{
		return $this->hasMany('App\RepoTag');
	}
}
