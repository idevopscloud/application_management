<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateApprovalTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::table('approvals', function($table) {
    		// $table->integer('user_id');
    		// $table->string('user_name', 50);
    	});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::table('approvals', function($table) {
    		// $table->dropColumn('user_id');
    		// $table->dropColumn('user_name');
    	});
    }
}
