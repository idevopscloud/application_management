<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateSvcBindingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::create('svc_bindings', function ($table) {
			$table->increments ( 'id' );
			$table->string('ipaddress', 2000);
			$table->integer ( 'port' );
    		$table->integer ( 'instance_id' );
    		$table->string ( 'component_name', 50 );
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
    	Schema::drop('svc_bindings');
    }
}
