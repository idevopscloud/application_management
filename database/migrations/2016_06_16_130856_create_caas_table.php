<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCaasTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::create('caas', function($table)
    	{
    		$table->uuid('id')->primary();
    		$table->string('name', 50)->unique();
    		$table->string('code_name', 50)->unique();
    		$table->string('description', 500);
    		$table->string('icon', 250);
    		$table->integer('master_user_id');
    		$table->string('master_user_name', 50);
    		$table->timestamps();
    	});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::drop('caas');
    }
}
