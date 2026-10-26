<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| The application is a Vue SPA: every non-API request renders the shell and
| the client-side router takes over. Module routes live in
| resources/js/router/routes.js
|
*/

Route::get('/{any?}', function () {
    return view('app');
})->where('any', '.*');
