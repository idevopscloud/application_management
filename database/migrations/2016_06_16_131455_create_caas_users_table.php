<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateCaasUsersTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::create('caas_users', function($table)
    	{
    		$table->string('caas_id', 50);
    		$table->integer('user_id');
    		$table->string('user_name');
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
    	Schema::drop('caas_users');
    }
}
