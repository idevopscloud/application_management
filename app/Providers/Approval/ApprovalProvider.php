<?php namespace App\Providers\Approval;

use App\Approval;
class ApprovalProvider
{
	const TIME = 86400;
	/**
	 * 
	 * @param int $instance_id
	 * @param int $user_id
	 * @param string $time
	 * @return boolean
	 */
	public function duplicateCheck($instance_id, $user_id, $time = 86400) {
		$approvals = Approval::where(['user_id' => $user_id, 'status' => 0])->where('created_at', '>', date ( 'Y-m-d H:i:s', time() - ($time ? : static::TIME)))->get();
		$exist = false;
		foreach ($approvals as $approval) {
			$data = json_decode($approval->data, true);
			if ($approval->type == 'deploy' && isset($data['app_instance_id']) && $data['app_instance_id'] == $instance_id) {
				$exist = true;
				break;
			} else if (($approval->type == 'instance_clean' || $approval->type == 'pod_restart') && isset($data['instance']['id']) && $data['instance']['id'] == $instance_id) {
				$exist = true;
				break;
			}
		}
		return $exist;
	}
}
