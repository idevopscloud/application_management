<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests;
use App\Providers\Api\ApiProvider;
use Illuminate\Support\Facades\Response;
use App\App;
use App\AppDeploy;
use Illuminate\Support\Facades\Validator;
use App\Providers\Application\AppInstanceProvider;
use App\PaasDeployEnv;
use App\Approval;
use Illuminate\Support\Facades\DB;
use App\SvcBinding;
use App\Providers\Approval\ApprovalProvider;

class ApprovalController extends Controller {
	
	public function index(Request $request) {
		$data = [ ];
		$apiProvider = new ApiProvider ();
		$query = Approval::where ( 'status', 0 )->where ( 'created_at', '>', date ( 'Y-m-d H:i:s', time() - ApprovalProvider::TIME ) );
		if ($request->action == 'mime') {
			$user = with ( new ApiProvider () )->getUserInfo ( [
					'token' => $request->token
			] );
			$query->where('user_id', $user['id']);
		} else {
			$roles = $apiProvider->getUserRoles ( [
					'token' => $request->token
					] );
			foreach ( $roles as $role ) {
				$role_ids [] = $role ['id'];
			}
			$query->whereIn ( 'approval_role_id', $role_ids );
		}
		if ($request->type) {
			$query->where('type', $request->type);
		}
		$approvals = $query->get();
		foreach ( $approvals as $approval ) {
			$approvalData = json_decode ( $approval->data, true );
			unset($approval->data);
			if ($approval->type == 'deploy') {
				$deploy = AppDeploy::with ( 'components' )->with ( 'instance' )->findOrFail ( $approvalData ['id'] );
				$count = $deploy->instance->deploys ()->count ();
				if ($count == 1) {
					$deploy->old_components = [ ];
				} else {
					$deploy->old_components = $deploy->instance->components ()->get ();
				}
				$approval->deploy = $deploy;
			} else if ($approval->type == 'pod_restart') {
				$approval->pod = $approvalData['pod'];
				$approval->instance = $approvalData['instance'];
			} else if ($approval->type == 'instance_clean') {
				$approval->instance = $approvalData['instance'];
			}
			$data [] = $approval;
		}
		
		return Response::Json ( $data );
	}
	
	
	public function update($id, Request $request) {
		$validator = Validator::make ( $request->all (), [ 
				'approve' => 'required' 
		] );
		
		if ($validator->fails ()) {
			throw new \Exception ( $validator->errors () );
		}
		$roles = with ( new ApiProvider () )->getUserRoles ( [ 
				'token' => $request->token 
		] );
		
		foreach ( $roles as $role ) {
			$role_ids [] = $role ['id'];
		}
		$approval = Approval::whereIn ( 'approval_role_id', $role_ids )->findOrFail ( $id );
		
		$approval->status = $request->approve == 1 ?  : 2;
		$approval->comment = $request->comment;
		
		DB::beginTransaction ();
		try {
			$approvalData = json_decode ( $approval->data, true );
			if ($approval->status == 1) {
				// 上线部署
				if ($approval->type == 'deploy') {
					$deploy = AppDeploy::with ( 'instance' )->findOrFail ( $approvalData ['id'] );
					
					$definition = with ( new AppInstanceProvider () )->setModel ( $deploy )->getDefinition ();
					$env = PaasDeployEnv::where ( 'location', $deploy->instance->node_group->isp )->first ();
					$re_deploy = 0;
					try {
						$status = do_request_paas ( "{$env->paas_api_url}/applications/{$deploy->instance->name}?summary=y", 'GET', [ ], null, $env->api_key );
						if (! isset ( $status ['stack_info'] ['stack_status'] )) { // stopped
							$re_deploy = 1;
						}
					} catch ( \Exception $e ) {
						if ($e->getCode () == '404') {
							$re_deploy = 1;
						}
					}
					// 部署线上环境
					if ($env) {
						$url = $env->paas_api_url . '/applications';
						do_request_paas ( $url, 'POST', $definition, null, $env->api_key );
					}
					
					with ( new AppInstanceProvider () )->syncComponentFromDeploy ( $deploy->id, $deploy->instance->id, $deploy->instance->app->company_id, $request->token, $re_deploy );
					$deploy->is_deploy = 1;
					$deploy->save ();

				// 线上实例清除
				} else if ($approval->type == 'instance_clean') {
					DB::beginTransaction ();
					try {
						$api_key = isset($approvalData['env']['api_key'])?$approvalData['env']['api_key']:null;
						SvcBinding::where ( 'instance_id', $approvalData['instance']['id'] )->forceDelete ();
						$result = do_request_paas($approvalData['url'], 'DELETE', [], null, $api_key );
					} catch (\Exception $e) {
						DB::rollBack();
						throw $e;
					} finally {
						AppInstanceProvider::recoverMemUsage($approvalData['instance']['id'], $approvalData['company_id'], $request->token);
					}
					DB::Commit();
					
				// 线上pod重启
				} else if ($approval->type == 'pod_restart') {
					$api_key = isset($approvalData['env']['api_key'])?$approvalData['env']['api_key']:null;
					$result = do_request_paas($approvalData['url'], 'DELETE', [], null,  $api_key);
					logger($result);
				}
			}
			$approval->save ();
		} catch ( \Exception $e ) {
			DB::rollBack ();
			logger ( $e );
			throw $e;
		}
		DB::Commit ();
		
		return Response::Json ( $approval );
	}
	
	/**
	 * 取消申请
	 * @param int $id
	 * @param Request $request
	 */
	public function destroy($id, Request $request) {
		$user = with(new ApiProvider())->getUserInfo(['token'=>$request->token ] );
		$approval = Approval::where('status', 0)->find($id);
		if ($user['id'] == $approval->user_id) {
			$approval->delete();
		} else {
			throw new \Exception(trans("exception.not_allowed"));
		}
		return Response::Json ( [] );
	}
	
}
