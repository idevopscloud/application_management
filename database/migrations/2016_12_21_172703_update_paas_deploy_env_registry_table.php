<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdatePaasDeployEnvRegistryTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::table('paas_deploy_env', function($table) {
            // $table->string('registry_id', 36);
            // $table->string('registry_name');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('paas_deploy_env', function($table) {
            // $table->dropColumn('registry_id');
            // $table->dropColumn('registry_name');
        });
    }
}
