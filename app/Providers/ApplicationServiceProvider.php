<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Providers\Application\AppInstanceComponentProvider;
use App\Providers\Application\AppInstanceProvider;
use App\Providers\Application\AppProvider;
use App\Providers\Application\AppDeployComponentProvider;

class ApplicationServiceProvider extends ServiceProvider
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
    	
    	$this->app->singleton(AppProvider::class, function ($app) {
    		return new AppProvider();
    	});
    	
    	$this->app->singleton(AppInstanceComponentProvider::class, function ($app) {
    		return new AppInstanceComponentProvider();
    	});
    	
    	$this->app->singleton(AppInstanceProvider::class, function ($app) {
    		return new AppInstanceProvider();
    	});
    	$this->app->singleton(AppDeployComponentProvider::class, function ($app) {
    		return new AppInstanceComponentProvider();
    	});
    			 
    }
    
    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
    	return [AppInstanceComponentProvider::class, AppInstanceProvider::class, AppProvider::class,AppDeployComponentProvider::class];
    }
}
