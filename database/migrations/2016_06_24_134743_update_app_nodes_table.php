<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateAppNodesTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::table('app_nodes', function ($table) {
    		// $table->string('public_ipaddress', 255)->after('ipaddress');
    	});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::table('app_nodes', function ($table) {
    		// $table->dropColumn('public_ipaddress');
    	});
    }
}
