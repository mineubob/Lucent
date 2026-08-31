<?php
declare(strict_types=1);


namespace Lucent\Database;

interface SqlSerializable
{
    public function toSql() : string;

}