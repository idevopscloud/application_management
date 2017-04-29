<?php

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

class UpdatePaasDeployEnvTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
    	Schema::create('paas_deploy_env', function($table)
    	{
    		$table->increments('id');
    		$table->string('name');
    		$table->string('paas_api_url');
    		$table->string('k8s_endpoint');
    		$table->string('location');
            $table->unique('location', 'env_location_unique');
            $table->string('registry_id', 36);
            $table->string('registry_name');
    		$table->timestamps();
    	});
    	
    	/*Schema::table('paas_deploy_env', function ($table) {
    		$table->integer('company_id')->nullable()->after('location');
    	});*/
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
    	/*Schema::table('paas_deploy_env', function ($table) {
    		$table->dropColumn('company_id');
    	});*/
    	Schema::drop('paas_deploy_env');
    }
}
