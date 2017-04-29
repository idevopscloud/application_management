<?php namespace App\Http\Controllers;

use Illuminate\Routing\Controller as BaseController;

use Illuminate\Http\Request;
use App\Http\Requests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Input;
use App\AppNodeGroup;
use App\AppNode;
use App\AppNodeGroupTeamRel;
use App\Providers\Api\ApiProvider;
use App\Providers\Env\EnvProvider;
use App\Providers\Application\AppProvider;

class NodeGroupController extends BaseController
{
	public function index(Request $request) {
		$ngs = [];
		$groups = [];

		if ($request->team_id) {
			$rels = AppNodeGroupTeamRel::with('ngs')->where('team_id', $request->team_id)->get()->toArray();
			return Response::Json($rels);
		}

		$user = with(new ApiProvider())->getUserInfo(['token'=>$request->token]);
		$roles = with(new ApiProvider)->getUserRoles(['token'=>$request->token]);
		foreach ($roles as $role) {
			if ($role['id'] == 2) { // 普通用户，只取他参与的应用的nodegroup
				$groups = with(new ApiProvider)->getUserRoleGroups(['token'=>$request->token]);
				break;
			}
		}
		if ($request->action == 'team') {
			$rels = AppNodeGroupTeamRel::with([
				'ngs'=> function($query){
					$query->with(['nodes', 'apps', 'instances']);
				}])->where(['team_id' => $user['company_id']])->get();
			foreach ($rels as $key => $rel) {
				$ngs[$rel->ngs->id] = $rel->ngs;
			}
		} else {
			$builder = AppNodeGroup::with([
				'cluster'=>function($query) use ($request) {
					$query->select('id', 'location', 'name');
				}, 
				'apps'=> function($query) use ($user, $groups, $request) {
					
					/*$query->with(['instances'=> function ($query) {
	    				$query->select('app_id', 'id', 'name', 'env_category');
						$query->with(['components'=> function($query){
							$query->select('app_instance_id', 'name');
						}]);
					}]);*/

					if (!$request->cluster) { // 获取用户所属公司的应用，否则获取该cluster所有的应用
						$query->where(['company_id' => $user['company_id']]);
					}
					
					if (count($groups) > 0) { // role of team member
						foreach ($groups as $group) {
							$group_ids[] = $group['id'];
						}
						$query->whereIn('role_group_id', $group_ids);
					}
					
				}, 
				'instances' => function($query) {
					$query->with('app');
				},
				'nodes',
				'teams'
			]);
			if ($request->cluster) { // 获取某一个cluster的信息
				$builder->where('isp', $request->cluster);
			}
			$ngs = $builder->get();
		}
		foreach ($ngs as $k=>&$ng) {
			$containers = [];
			foreach( $ng->instances as $ins) {
				$containers = array_merge($containers, $ins->components->toArray());
			}
			$ng->containers = count($containers);
		}
		// $ngs = $ngs->toArray();
		return Response::Json($ngs);
	}
	
	
	public function show(Request $request, $id) {
		$nodes = [];
		$ng = AppNodeGroup::with(['cluster', 'nodes'])->findOrFail($id);
		if ($ng->cluster) {
			$monitor_nodes = with(new EnvProvider())->getNodes($ng->cluster);
			foreach ($ng->nodes as $node) {
				foreach ($monitor_nodes['items'] as $item) {
					if ($item['name'] == $node->ipaddress) {
						$nodes[] = $item;
					}
				}
			}
		}
		return Response::Json($nodes);
	}

	public function store(Request $request) {
		$validator = validator ( $request->all (), [
			'name' => 'required|unique:app_node_groups,name',
			'cluster' => 'required|exists:paas_deploy_env,location',
			'nodes' => 'required'
		] );
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}
		DB::beginTransaction();
		try {
			$ngData = [
				'id' => gen_uuid(),
				'name' => $request->name,
				'isp' => $request->cluster,
			];
			if ($request->product == 'on') {
				$ngData['env_category'] = 'product';
			} else {
				$ngData['env_category'] = 'develop';
			}
			$ng = new AppNodeGroup($ngData);
			$ng->save();

			$nodes = array_unique(explode(',', $request->nodes));
			$node_data = with(new EnvProvider())->getNodes($ng->cluster);
			if (!empty($node_data['items'])) {
				foreach ($node_data['items'] as $item) {
					foreach ($nodes as $node) {
						if ($node == $item['IP']) {
							$ipaddress = isset($item['public_address']) ? $item['public_address'] : $item['IP'];
							$nodeData = [
								'ipaddress' => $item['IP'],
								'public_ipaddress' => $ipaddress,
								'isp' => $ng->isp,
								'group_id' => $ng->id,
							];
							$appNode = new AppNode($nodeData);
							$appNode->save();
							with(new AppProvider())->patchNodeLabel($ng->cluster, $item['IP'],[['op'=>'add', 'path'=>"/labels/idevops.env.{$ng->id}", 'value'=>'Y']]);
						}
					}
				}
			}
		} catch (\Exception $e) {
			DB::rollBack();
			throw $e;
		}
		DB::Commit();
		return Response::Json([]);
	}

	public function update(Request $request, $id) {
		$ids = explode(',', $id);

		// update nodes
		if ($request->ng_nodes) {
			$ng = AppNodeGroup::with('nodes')->find($id);
			DB::beginTransaction();
			try {
				foreach ($ng->nodes as $node) { // remove old node and labels
					with(new AppProvider())->patchNodeLabel($ng->cluster, $node->ipaddress, [
						[
							'op'=>'remove',
							'path'=>"/labels/idevops.env.{$ng->id}",
							'value'=>'Y'
						]
					]);
					$node->delete();
				}

				// new nodes of node-group
				$nodes = array_unique(explode(',', $request->ng_nodes));
				$node_data = with(new EnvProvider())->getNodes($ng->cluster);
				if (!empty($node_data['items'])) {
					foreach ($node_data['items'] as $item) {
						foreach ($nodes as $node) {
							if ($node == $item['IP']) {
								$ipaddress = isset($item['public_address']) ? $item['public_address'] : $item['IP'];
								$nodeData = [
									'ipaddress' => $item['IP'],
									'public_ipaddress' => $ipaddress,
									'isp' => $ng->isp,
									'group_id' => $ng->id,
								];
								$appNode = new AppNode($nodeData);
								$appNode->save();
								with(new AppProvider())->patchNodeLabel($ng->cluster, $item['IP'],[
									[
										'op'=>'add', 
										'path'=>"/labels/idevops.env.{$ng->id}", 
										'value'=>'Y'
									]
								]);
							}
						}
					}
				}
			} catch (\Exception $e) {
				DB::rollBack();
				throw $e;
			}
			DB::Commit();
			return Response::Json([]);
		}

		// update team relations
		$validator = validator ( $request->all (), [
			'team_id' => 'required',
			'team_name' => 'required'
		] );
		if ($validator->fails()) {
			throw new \Exception($validator->errors());
		}
		DB::beginTransaction();
		try {
			foreach ($ids as $id) {
				$data = [
					'group_id' => $id,
					'team_id' => $request->team_id,
					'team_name' => $request->team_name
				];
				if ($request->action == 'grant') { 
					AppNodeGroupTeamRel::updateOrCreate($data);
				} else if ($request->action == 'strip') {
					AppNodeGroupTeamRel::where($data)->delete();
				}
			}
		} catch (\Exception $e) {
			DB::rollBack();
			throw $e;
		}
		DB::Commit();
		return Response::Json([]);
	}

	public function destroy($id, Request $request) {
		$ng = AppNodeGroup::with('teams')->findOrFail($id);
		if (count($ng->teams)>0) {
			throw new \Exception(trans('exception.not_allowed').trans('exception.blocked_by_team'));
		}
		DB::beginTransaction();
		try {
			AppNodeGroupTeamRel::where(['group_id'=>$id])->delete();
			$ng->delete();
		} catch (\Exception $e) {
			DB::rollBack();
			throw $e;
		}
		DB::Commit();
		return Response::Json([]);
	}
	
}