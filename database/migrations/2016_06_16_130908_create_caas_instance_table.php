<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCaasInstanceTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::create('caas_instances', function($table)
    	{
    		$table->uuid('id')->primary();
    		$table->string('caas_id', 50);
    		$table->string('repo_id');
    		$table->integer('port')->unsigned();
    		$table->string('name', 50)->unique();
    		$table->integer('master_user_id');
    		$table->string('master_user_name', '50');
    		$table->timestamps();
    		$table->foreign('caas_id')->references('id')->on('caas')->onDelete('cascade');
    	});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::drop('caas_instances');
    }
}
