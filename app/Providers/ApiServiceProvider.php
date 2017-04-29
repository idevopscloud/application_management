<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Providers\Api\ApiProvider;

class ApiServiceProvider extends ServiceProvider
{
	
	/**
	 * Indicates if loading of the provider is deferred.
	 *
	 * @var bool
	 */
	protected $defer = true;
	
    /**
     * Bootstrap the application services.
     *
     * @return void
     */
    public function boot()
    {
        //
    }

    /**
     * Register the application services.
     *
     * @return void
     */
    public function register()
    {
    	$this->app->singleton(ApiProvider::class, function ($app) {
    		return new ApiProvider();
    	});
    }
    
    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
    	return [ApiProvider::class];
    }
}
