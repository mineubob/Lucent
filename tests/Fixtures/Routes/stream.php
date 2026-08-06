<?php
    use Lucent\Facades\Route;
    use App\Controllers\StreamTestController;
    
    Route::rest()->group("stream")
        ->prefix("/stream")
        ->defaultController(StreamTestController::class)
        ->get(path: "/10seconds", method: "stream");