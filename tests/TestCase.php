<?php

class TestCase extends Illuminate\Foundation\Testing\TestCase
{
    /**
     * The base URL to use while testing the application.
     *
     * @var string
     */
    protected $baseUrl = 'http://localhost';

    protected $token = null;
    
    public function __construct() {
    	$this->afterApplicationCreatedCallbacks[] = function(){
    		global $token;
    		if (!$token) {
		    	$result = do_request('http://api.idevops.net/signin', 'POST', ['name'=>'tom.wang', 'password'=>'123456.com']);
		    	$token = $result['token'];
    		}
    	};
    }
    /**
     * Creates the application.
     *
     * @return \Illuminate\Foundation\Application
     */
    public function createApplication()
    {
        $app = require __DIR__.'/../bootstrap/app.php';

        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

        return $app;
    }
}
