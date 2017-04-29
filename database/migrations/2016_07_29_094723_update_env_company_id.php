<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdateEnvCompanyId extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::table('paas_deploy_env', function ($table) {
    		// $table->string('company_id', 36)->change();
      //       $table->unique('location', 'env_location_unique');
    	});
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	Schema::table('paas_deploy_env', function ($table) {
    		// $table->integer('company_id')->change();
      //       $table->dropUnique('env_location_unique');
    	});
    }
}
