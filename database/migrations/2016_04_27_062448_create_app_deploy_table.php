<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAppDeployTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('app_deploys', function($table)
    	{
    		$table->increments('id');
    		$table->integer('app_instance_id')->unsigned();
    		$table->integer('user_id');
    		$table->string('user_name', 50);
    		$table->tinyInteger('is_deploy')->default(0);
    		$table->tinyInteger('status')->default(0);
    		$table->timestamps();
    		$table->softDeletes();
    		$table->foreign('app_instance_id')->references('id')->on('app_instances')->onDelete('cascade');
    	});
        
        Schema::create('app_deploy_components', function($table)
        {
        	$table->increments('id');
        	$table->integer('app_deploy_id')->unsigned();
        	$table->string('name', 50);
        	$table->string('version', 30)->nullable();
        	$table->mediumText('definition');
        	$table->index(['app_deploy_id', 'name']);
        	$table->timestamps();
        	$table->softDeletes();
        	$table->foreign('app_deploy_id')->references('id')->on('app_deploys')->onDelete('cascade');
        });
         
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::drop('app_deploy_components');
    	Schema::drop('app_deploys');
    }
}
