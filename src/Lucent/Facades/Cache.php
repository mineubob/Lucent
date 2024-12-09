<?php

namespace Lucent\Facades;


use Lucent\Application;
use Lucent\Model;

class Cache
{

    public static function storeModel(string $table, string $pk, Model $model): void
    {
        Application::getInstance()->addModelToCache($table,$pk,$model);
    }

    public static function getModel($table,$pk): Model
    {
        return  Application::getInstance()->getModelFromCache($table,$pk);
    }

}