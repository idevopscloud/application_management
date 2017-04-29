<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use App\Http\Requests;
use App\Repo;
use Illuminate\Support\Facades\Response;
use App\App;
use App\Exceptions\CdException;

class RepoController extends Controller
{
    public function index() {
    	$repos = Repo::select('id', 'name', 'description')->get()->all();
    	return Response::Json($repos);
	}
	
	public function show($id) {
		$repo = Repo::with('tags')->find($id);
		return Response::Json($repo);
	}
	
	public function store(Request $request) {
		$validate_rules = [ 
				'app_id' => 'required|exists:apps,id',
				'base_image' => 'required',
				'base_image_tag' => 'required',
				'image_name' => 'required',
				'image_tag' => 'required',
				'commands' => 'required',
		];
		$validator = validator($request->all(), $validate_rules);
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}
		$app = App::findOrFail($request->app_id);
		$data = [
			'img_in' => "{$request->base_image}:{$request->base_image_tag}",
			'img_out' => "aws-seoul.repo.idevopscloud.com:5000/{$app->name}/{$request->image_name}:{$request->image_tag}",
			'repo_usr' => config('cd.repo_user'),
			'repo_pwd' => config('cd.repo_pwd'),
			'commands' => $request->commands,
			'callback' => config('api.idevops_host')."/third/registry/repos/"
		];
		try {
			$cd_resp = do_request_cd(config('cd.host')."/job/base_img/buildWithParameters", "GET", $data);
		} catch (CdException $e) {
			throw new \Exception(trans("exception.request_cd_error"));
		} catch (\Exception $e) {
			throw $e;
		}
		return Response::Json([]);
	}
}
