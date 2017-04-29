<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\PaasDeployEnv;
use Illuminate\Support\Facades\Response;
use App\AppDeploy;
use App\App;

class DeployController extends Controller {
	public function index(Request $request) {
		$deploys = [ ];
		$app = App::with ( [ 
				'instances' => function ($query) use($request) {
					
					$query->with ( [ 
							'deploys' => function ($query) {
								$query->where ( 'status', 1 )->orderBy('id', 'desc');
							} 
					] );
					if ($request->action == 'build_number') {
						$query->where ( 'env_category', 'develop' );
					}
					if ($request->instance_id) {
						$query->findOrFail ( $request->instance_id );
					}
				} 
		] )->findOrFail ( $request->app_id );
		
		foreach ( $app->instances as $instance ) {
			foreach ( $instance->deploys as $deploy ) {
				$deploy->instance_name = $instance->name;
    				$deploy->components = $deploy->components()->get();
    				$deploys[] = $deploy;
    			}
    		}
    	return Response::Json($deploys);
	}
	
}
