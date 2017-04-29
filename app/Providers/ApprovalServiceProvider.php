<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Providers\Approval\ApprovalProvider;

class ApprovalServiceProvider extends ServiceProvider
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
    	$this->app->singleton(ApprovalProvider::class, function ($app) {
    		return new ApprovalProvider();
    	});
    }
    
    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
    	return [ApprovalProvider::class];
    }
}
