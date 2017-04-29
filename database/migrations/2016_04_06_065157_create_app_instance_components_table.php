<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAppInstanceComponentsTable extends Migration
{
  /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::create('app_instance_components', function($table)
    	{
    		$table->increments('id');
    		$table->integer('app_instance_id')->unsigned();
    		$table->string('name', 50);
    		$table->string('version', 30)->nullable();
    		$table->mediumText('definition');
    		$table->index(['app_instance_id', 'name']);
    		$table->timestamps();
    		$table->softDeletes();
    		$table->foreign('app_instance_id')->references('id')->on('app_instances')->onDelete('cascade');
    	});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::drop('app_instance_components');
    }
}
