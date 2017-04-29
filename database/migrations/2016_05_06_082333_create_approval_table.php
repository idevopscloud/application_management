<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateApprovalTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::create('approvals', function($table)
    	{
	    	$table->increments('id');
	    	$table->integer('approval_role_id');
            $table->integer('user_id');
            $table->string('user_name', 50);
	    	$table->string('type', 15);
	    	$table->tinyInteger('status')->default(0);
	    	$table->mediumText('data');
	    	$table->string('comment', 250);
	    	$table->timestamps();
	    	$table->softDeletes();
    	});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::drop('approvals');
    }
}
