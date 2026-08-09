<?php
    use App\Controllers\GlobalMiddlewareTestingController;
    use App\Middleware\AuthMiddleware;

    use Lucent\Facades\Route;

    Route::rest()->group("global")
        ->prefix("/global")
        ->defaultController(GlobalMiddlewareTestingController::class)
        ->get(path: "/ok", method: "ok")
        ->get(path: "/boom", method: "boom");

    Route::rest()->group("global_route_mw")
        ->prefix("/global-route-mw")
        ->defaultController(GlobalMiddlewareTestingController::class)
        ->middleware([AuthMiddleware::class])
        ->get(path: "/ok", method: "ok");