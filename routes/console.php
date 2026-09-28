<?php

use App\Application\Settlement\Commands\RecoverUnknownSettlements;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('settlements:recover-unknown', function (RecoverUnknownSettlements $recover): void {
    $this->info('Recovered '.$recover->execute().' unknown settlement(s).');
})->purpose('Safely retry settlements with ambiguous provider submission outcomes');
