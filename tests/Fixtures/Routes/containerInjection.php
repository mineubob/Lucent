<?php
    use App\Controllers\ContainerInjectionController;
    use Lucent\Facades\Route;

    Route::rest()->group("container-injection")
        ->prefix("/container")
        ->defaultController(ContainerInjectionController::class)
        ->get(path: "/inject", method: "greet");
