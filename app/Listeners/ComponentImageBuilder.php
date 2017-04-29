<?php

namespace App\Listeners;

use App\Events\ComponentImageBuilderEvent;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use App\Providers\Api\ApiProvider;

class ComponentImageBuilder implements ShouldQueue
{
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
     * @param  ComponentImageBuilderEvent  $event
     * @return void
     */
    public function handle(ComponentImageBuilderEvent $event)
    {
    	$post = with(new ApiProvider)->buildComponentImage($event->data);
    }
}
