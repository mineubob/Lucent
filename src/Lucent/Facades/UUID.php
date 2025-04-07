<?php

namespace Lucent\Facades;

use Random\RandomException;

/**
 * UUID Facade
 *
 * Provides static methods for generating and validating UUIDs.
 *
 * @package Lucent\Facades
 */
class UUID
{
    /**
     * Generates a version 4 (random) UUID
     * Follows RFC 4122 standard
     *
     * @return string UUID in format: xxxxxxxx-xxxx-4xxx-[89ab]xxx-xxxxxxxxxxxx
     * @throws RandomException
     */
    public static function generate(): string
    {
        // Generate 16 bytes of random data
        $data = random_bytes(16);

        // Set version to 0100 (4)
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);

        // Set bits 6-7 to 10
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        // Format the bytes into a UUID string
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Generates a time-ordered UUID v7
     * Based on the draft RFC for UUIDv7 (2023)
     * Provides improved sequential sorting while maintaining uniqueness
     *
     * @return string UUID in format: xxxxxxxx-xxxx-7xxx-[89ab]xxx-xxxxxxxxxxxx
     * @throws RandomException
     */
    public static function v7(): string
    {
        // Get current Unix timestamp in milliseconds (48 bits)
        $timestamp = floor(microtime(true) * 1000);
        $time_hex = str_pad(dechex($timestamp), 12, '0', STR_PAD_LEFT);

        // Random bytes for remaining fields
        $random = random_bytes(10);

        // Format UUID fields
        $time_low = substr($time_hex, 0, 8);
        $time_mid = substr($time_hex, 8, 4);

        // Random part as hex
        $random_hex = bin2hex($random);

        // Set version to 7 (0111)
        $version_byte = hexdec(substr($random_hex, 0, 2)) & 0x0f | 0x70;
        $version_hex = str_pad(dechex($version_byte), 2, '0', STR_PAD_LEFT);

        // Set variant to 10xx (RFC 4122)
        $variant_byte = hexdec(substr($random_hex, 2, 2)) & 0x3f | 0x80;
        $variant_hex = str_pad(dechex($variant_byte), 2, '0', STR_PAD_LEFT);

        return sprintf(
            '%s-%s-%s%s-%s%s-%s',
            $time_low,
            $time_mid,
            $version_hex,
            substr($random_hex, 4, 2),
            $variant_hex,
            substr($random_hex, 6, 2),
            substr($random_hex, 8, 12)
        );
    }

    /**
     * Validates if a string is a valid UUID
     *
     * @param string $uuid The string to validate
     * @param int|null $version Specific UUID version to validate (null for any version)
     * @return bool True if valid UUID, false otherwise
     */
    public static function isValid(string $uuid, ?int $version = null): bool
    {
        if ($version !== null) {
            // Validate specific version
            $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-' . $version . '[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        } else {
            // Validate any UUID version
            $pattern = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-7][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';
        }

        return preg_match($pattern, $uuid) === 1;
    }

    /**
     * Extract the version of a UUID
     *
     * @param string $uuid The UUID to check
     * @return int|null The UUID version (1-7) or null if invalid
     */
    public static function getVersion(string $uuid): ?int
    {
        if (!self::isValid($uuid)) {
            return null;
        }

        return (int) $uuid[14];
    }

    /**
     * Generate a UUID with namespace (v5)
     * Uses SHA-1 hashing algorithm
     *
     * @param string $namespace Namespace UUID
     * @param string $name The name to generate a UUID for
     * @return string UUID v5
     */
    public static function v5(string $namespace, string $name): string
    {
        // Remove hyphens and convert to binary
        $namespace = hex2bin(str_replace('-', '', $namespace));

        // Create hash
        $hash = sha1($namespace . $name);

        // Format UUID with version 5
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hash, 0, 8),
            substr($hash, 8, 4),
            '5' . substr($hash, 13, 3),
            dechex(hexdec(substr($hash, 16, 2)) & 0x3f | 0x80) . substr($hash, 18, 2),
            substr($hash, 20, 12)
        );
    }

    /**
     * Convert a UUID to its binary representation
     *
     * @param string $uuid UUID string
     * @return string|null Binary representation or null if invalid
     */
    public static function toBinary(string $uuid): ?string
    {
        if (!self::isValid($uuid)) {
            return null;
        }

        return hex2bin(str_replace('-', '', $uuid));
    }

    /**
     * Convert a binary representation back to a UUID string
     *
     * @param string $binary Binary UUID
     * @return string|null Formatted UUID string or null if invalid
     */
    public static function fromBinary(string $binary): ?string
    {
        if (strlen($binary) !== 16) {
            return null;
        }

        $hex = bin2hex($binary);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    /**
     * Generate a nil UUID (all zeros)
     *
     * @return string Nil UUID
     */
    public static function nil(): string
    {
        return '00000000-0000-0000-0000-000000000000';
    }

    /**
     * Common namespace UUIDs for use with v5()
     *
     * @var array
     */
    public static $namespaces = [
        'dns' => '6ba7b810-9dad-11d1-80b4-00c04fd430c8',
        'url' => '6ba7b811-9dad-11d1-80b4-00c04fd430c8',
        'oid' => '6ba7b812-9dad-11d1-80b4-00c04fd430c8',
        'x500' => '6ba7b814-9dad-11d1-80b4-00c04fd430c8',
    ];
}