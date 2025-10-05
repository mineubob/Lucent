<?php

namespace Lucent\Database;

interface SqlSerializable
{
    public function toSql() : string;

}