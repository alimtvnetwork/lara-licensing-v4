<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('br:kill-switch:drill')->quarterly();