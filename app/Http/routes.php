<?php

/*
|--------------------------------------------------------------------------
| Application Routes
|--------------------------------------------------------------------------
|
| Here is where you can register all of the routes for an application.
| It's a breeze. Simply tell Laravel the URIs it should respond to
| and give it the controller to call when that URI is requested.
|
*/

Route::match(['post', 'options'], '/upload', ['uses' => 'UploadController@index', 'middleware' => 'cors']);

Route::group(['middleware' => ['api']], function () {
	Route::resource('apps', 'AppController');
});

Route::group(['middleware' => ['api'], 'prefix'=>'app'], function () {
	Route::resource('instances', 'AppInstanceController');
	Route::post('instance/deploy', 'AppInstanceController@deploy');
	Route::post('instance/cancel', 'AppInstanceController@cancel');
	Route::post('instance/clean', 'AppInstanceController@clean');
	Route::post('instance/podrestart', 'AppInstanceController@podRestart');
	Route::post('instance/sync', 'AppInstanceController@syncComponent');
	
	Route::resource('components', 'AppInstanceComponentController');
	
	Route::resource('envs', 'EnvController');
	Route::get('env/platform', 'EnvController@getPlatform');
	Route::resource('clusters', 'EnvController');
	Route::resource('ngs', 'NodeGroupController');
	
	Route::resource('approvals', 'ApprovalController');
	Route::resource('deploys', 'DeployController');
});

Route::group(['middleware' => ['api'], 'prefix'=>'caas'], function () {
	Route::resource('instances', 'CaasInstanceController');
	Route::resource('repos', 'RepoController');
	Route::resource('repo/tags', 'RepoTagController');
});

Route::group(['middleware' => ['api']], function () {
	Route::resource('repos', 'RepoController');
});
Route::get('error', function(){
    throw new \Exception('ForbiddenException');
});
