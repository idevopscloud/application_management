<?php

namespace App\Providers;

use Illuminate\Contracts\Events\Dispatcher as DispatcherContract;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
	/**
	 * The event listener mappings for the application.
	 *
	 * @var array
	 */
	protected $listen = [ 
			'App\Events\AppCreateEvent' => [ 
					'App\Listeners\AppUpdateListener' 
			],
			'App\Events\DeployApprovalEvent' => [
    				// 'App\Listeners\ApprovalListener',
					'App\Listeners\DeployListener',
    		],
    		'App\Events\ComponentImageBuilderEvent' => [
				'App\Listeners\ComponentImageBuilder'
			]
    ];

    /**
     * Register any other events for your application.
     *
     * @param  \Illuminate\Contracts\Events\Dispatcher  $events
     * @return void
     */
    public function boot(DispatcherContract $events)
    {
        parent::boot($events);

        //
    }
}
