<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class RepoTag extends Model
{
	protected $table = 'repo_tags';
	
	public $incrementing = false;
	
	protected $dates = ['deleted_at'];
	
	protected $fillable = [
	];
}
