<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('tickets:check-sla')->everyFiveMinutes();