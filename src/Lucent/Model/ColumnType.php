<?php

namespace Lucent\Model;

enum ColumnType: string
{
    case BINARY = "binary";
    case TINYINT = "tinyint";
    case DECIMAL = "decimal";
    case INT = "int";
    case JSON = "json";
    case TIMESTAMP = "timestamp";
    case ENUM = "enum";
    case DATE = "date";
    case TEXT = "text";
    case VARCHAR = "varchar";
    case FLOAT = "float";
    case DOUBLE = "double";
    case BOOLEAN = "boolean";
    case CHAR = "char";
    case LONGTEXT = "longtext";
    case MEDIUMTEXT = "mediumtext";
    case BIGINT = "bigint";
}