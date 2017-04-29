<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAppInstancesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::create('app_instances', function($table)
    	{
    		$table->increments('id');
    		$table->string('app_id', 50);
    		$table->string('name', 50)->unique();
    		$table->enum('env_category', ['develop', 'product']);
    		$table->string('node_group_id');
    		$table->integer('master_user_id');
		    $table->string('master_user_name', '50');
// 		    $table->index(['app_id', 'name']);
    		$table->timestamps();
    		$table->softDeletes();
    		$table->foreign('app_id')->references('id')->on('apps')->onDelete('cascade');
    	});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::drop('app_instances');
    }
}
