<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateSvcBindingsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::table('svc_bindings', function($table) {
    		// $table->string('ipaddress', 255)->change();
    	});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::table('svc_bindings', function($table) {
    		// $table->string ( 'ipaddress', 15 )->change();
    	});
    }
}
