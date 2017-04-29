<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateAppNodeGroupsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('app_node_groups', function($table) {
            // $table->enum('env_category', ['develop', 'product']);
            // $table->dropForeign(['app_id']);
        });

        Schema::table('app_nodes', function($table) {
            // $table->dropForeign(['app_id']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('app_node_groups', function($table) {
            // $table->dropColumn('env_category');
            // $table->foreign('app_id')->references('id')->on('apps')->onDelete('cascade');
        });

        Schema::table('app_nodes', function($table) {
            // $table->foreign('app_id')->references('id')->on('apps')->onDelete('cascade');
        });
    }
}
