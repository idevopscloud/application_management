<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class CreateAppAppNodeGroupRelTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('app_app_node_group_rel', function (Blueprint $table) {
            $table->string('app_node_group_id')->index();
            $table->foreign('app_node_group_id')->references('id')->on('app_node_groups')->onDelete('cascade');
            $table->string('app_id')->index();
            $table->foreign('app_id')->references('id')->on('apps')->onDelete('cascade');
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
        Schema::drop('app_app_node_group_rel');
    }
}
