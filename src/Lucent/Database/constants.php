<?php
const LUCENT_DB_BINARY = 1;
const LUCENT_DB_TINYINT = 2;
const LUCENT_DB_DECIMAL = 3;
const LUCENT_DB_INT = 4;
const LUCENT_DB_JSON = 5;
const LUCENT_DB_TIMESTAMP = 6;
const LUCENT_DB_ENUM = 7;
const LUCENT_DB_DATE = 8;
const LUCENT_DB_TEXT = 10;
const LUCENT_DB_VARCHAR = 12;

// Additional useful constants
const LUCENT_DB_DEFAULT_CURRENT_TIMESTAMP = 'CURRENT_TIMESTAMP';

// You might also want to add these for completeness
const LUCENT_DB_FLOAT = 13;
const LUCENT_DB_DOUBLE = 14;
const LUCENT_DB_BOOLEAN = 15;
const LUCENT_DB_CHAR = 16;
const LUCENT_DB_LONGTEXT = 17;
const LUCENT_DB_MEDIUMTEXT = 18;

// NEW: Add BIGINT support
const LUCENT_DB_BIGINT = 19;

// NEW: Add UNSIGNED modifier (can be combined with other types)
const LUCENT_DB_UNSIGNED = 'UNSIGNED';
