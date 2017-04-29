<?php

use Illuminate\Foundation\Testing\WithoutMiddleware;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;

class CaaSInstanceTest extends TestCase
{
// 	use WithoutMiddleware;
	
	private $name;
	public function __construct() {
		parent::__construct();
		$this->name = 'redis-' . time();
	}
    /**
     * @group caas
     */
    public function testCreate() {
		global $token;
		$this->seeInDatabase ( 'repos', [ 
				'name' => 'redis' 
		] );
		$response = $this->json ( 'POST', '/caas/instances', [ 
				'token' => $token,
				'name' => $this->name,
         		'image' => '876c158c-2224-11e6-8544-000c2925f8f6',
         		'version' => '2.6',
				'port' => '6379',
         		'requests_memory' => 1
        ])->seeJson(['flag'=>'success']);
    }
    
    /**
     * @group caas
     */
    public function testDestroy() {
    	global $token;
    	sleep(60); // sleep until instance created
    	$response = $this->json ( 'DELETE', "/caas/instances/{$this->name}", [
    			'token' => $token
    	])->seeJson(['flag'=>'success']);
    }
}
