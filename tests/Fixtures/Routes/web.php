<?php
    use App\Controllers\RouteGroupTestingController;
    use App\Controllers\SecondRestController;
    use App\Controllers\UserController;
    use App\Middleware\AuthMiddleware;

    use Lucent\Facades\Route;

    Route::rest()->group("rest_test")
        ->prefix("/test")
        ->defaultController(RouteGroupTestingController::class)
        ->get(path: "/one/{input}",method:"one")
        ->post(path:"/two",method: "two")
        ->get(path: "/three",method:"three")
        ->get(path: "/four",method:"test",controller: TestControllerAbc::class)
        ->get(path: "/five",method:"test",controller: SecondRestController::class);
        

    Route::rest()->group("user")
        ->prefix("/user")
        ->defaultController(UserController::class)
        ->get(path: "/{id}",method:"getById")
        ->get(path: "/object/{user}",method:"getModelById");
        
    Route::rest()->group("user2")
        ->prefix("/user2")
        ->defaultController(UserController::class)
        ->middleware([AuthMiddleware::class])
        ->get(path: "/object/{user}",method:"getModelById");