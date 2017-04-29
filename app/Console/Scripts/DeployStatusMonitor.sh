#!/bin/bash

while true
do
    cd /idevops/app/application_management && php artisan schedule:run >> /dev/null 2>&1
    sleep 3
done

