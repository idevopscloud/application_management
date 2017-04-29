<?php

namespace App\Listeners;

use App\Events\AppCreateEvent;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use App\App;
use App\Providers\Api\ApiProvider;

class AppUpdateListener implements ShouldQueue
{
	use InteractsWithQueue;
	
	
	
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param  AppCreateEvent  $event
     * @return void
     */
    public function handle(AppCreateEvent $event)
    {
    	$apiProvider = new ApiProvider;
    	$app = App::withTrashed()->find($event->app_id);
    	$users = $event->users;
        if (empty($users)) {
            $this->delete();
        }
    	$roles = [];
        try {
	    	if ($app && $app->role_group_id) { // update group (add role or user)
	    		
	    		$group = $apiProvider->getGroups(['token'=>$event->token, 'id'=>$app->role_group_id]); // get group info include roles and user in role
	    		if($group['roles']) {
	    			foreach ($group['roles'] as $role) {
	    				$group_users[$role['name']] = [];
	    			}
	    		}
	    		foreach($users as $role_name=>$user_ids) {
	    			unset($users[$role_name]);
	    			$users[$app->name . '-' . $role_name] = $user_ids;
	    		}
	    		
	    		$users = array_merge($group_users, $users);
	    		$role_ids = [];
	    		foreach($users as $role_name=>$user_ids) { // ['rolename'=>[uid1, uid2,...]]
	 
		    		if($group['roles']) {
		    			$role_exist = false;
		    			foreach ($group['roles'] as $role) {
		    				if ($role['name'] == $role_name) { // role exist ? true (add user to role) : false (create role and add user to role)
		    					$role_exist = true;
		    					
	    						$apiProvider->updateRoles ( [
	    								'token' => $event->token,
	    								'access_token' => $event->access_token,
	    								'id' => $role['id'],
	    								'users' => $user_ids
	    						] );
				    				
		    					break;
		    				}
		    			}
		    			if ($role_exist == false) {// create role and add user to role
		    				
		    				$role = $apiProvider->createRole ( [ 
									'token' => $event->token,
									'access_token' => $event->access_token,
									'name' => $role_name,
									'users' => $user_ids 
							] );
		    				$role_ids[] = $role['id'];
		    			}
		    		}
	    		}
	    		if ($role_ids) { // add role to group
		    		$apiProvider->updateGroups ( [ 
							'token' => $event->token,
							'access_token' => $event->access_token,
		    				'id'=>$app->role_group_id,
							'roles' => $role_ids 
					] );
	    		}
	    		
	    	} else { // new
		    	foreach ($users as $role=>$user_ids) {
		    		$role_name = $app->name . '-' . $role;
		    		$role = $apiProvider->createRole ( [ 
							'token' => $event->token,
							'access_token' => $event->access_token,
							'name' => $role_name,
							'users' => $user_ids 
					] );
		    		$role_ids[] = $role['id'];
		    	}
		    	$group_name = $app->name . '-members';
		    	$role_group = $apiProvider->createGroup ( [ 
						'token' => $event->token,
						'access_token' => $event->access_token,
						'roles' => $role_ids,
						'name' => $group_name 
				] );
		    	$app->role_group_id = $role_group['id'];
		    	$app->save();
	    	}
	    	if ($app->trashed()) { // 清除完用户信息后，彻底删除
	    		$app->forceDelete();
	    	}
        } catch (\Exception $e) {
            $this->delete();
            throw $e;
        }
    }
}
