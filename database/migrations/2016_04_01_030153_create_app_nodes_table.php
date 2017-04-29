<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAppNodesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::create('app_node_groups', function($table)
    	{
    		$table->uuid('id')->primary();
    		$table->string('name', 20);
    		// $table->string('app_id', 50);
    		$table->string('isp', 20);
            $table->enum('env_category', ['develop', 'product']);
    		$table->timestamps();
    		$table->softDeletes();
    		// $table->foreign('app_id')->references('id')->on('apps')->onDelete('cascade');
    	});
    	
    	Schema::create('app_nodes', function($table)
    	{
    		$table->increments('id');
    		// $table->string('app_id', 50);
    		$table->string('group_id');
    		$table->string('ipaddress', 20);
            $table->string('public_ipaddress', 2000);
    		$table->string('isp', 20);
    		$table->timestamps();
    		$table->softDeletes();
    		// $table->foreign('app_id')->references('id')->on('apps')->onDelete('cascade');
    		$table->foreign('group_id')->references('id')->on('app_node_groups')->onDelete('cascade');
    	});
    	
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::drop('app_nodes');
    	Schema::drop('app_node_groups');
    }
}
