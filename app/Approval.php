<?php

namespace App;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Approval extends Model
{
	use SoftDeletes;
	
	protected $table = 'approvals';
	
	protected $dates = ['deleted_at'];
	
	protected $fillable = [
			'id', 'type', 'data', 'status', 'approval_role_id', 'user_id', 'user_name', 'comment'
	];
}
