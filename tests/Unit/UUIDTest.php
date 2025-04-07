<?php

namespace Tests\Unit;

use Lucent\Facades\UUID;
use PHPUnit\Framework\TestCase;

class UUIDTest extends TestCase
{
    /**
     * Test that UUID::generate() produces valid UUIDs
     */
    public function test_generate_passing()
    {
        // Generate multiple UUIDs to test
        $uuids = [];
        for ($i = 0; $i < 100; $i++) {
            $uuids[] = UUID::generate();
        }

        // Test that all generated UUIDs are valid
        foreach ($uuids as $uuid) {
            $this->assertTrue(
                UUID::isValid($uuid),
                "UUID {$uuid} is not valid"
            );

            // Test they are all version 4
            $this->assertEquals(
                4,
                UUID::getVersion($uuid),
                "UUID {$uuid} is not version 4"
            );

            // Test specific v4 pattern (random)
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $uuid,
                "UUID {$uuid} does not match v4 pattern"
            );
        }

        // Check uniqueness
        $this->assertCount(
            count($uuids),
            array_unique($uuids),
            "Duplicate UUIDs were generated"
        );
    }

    /**
     * Test that UUID::v7() produces valid time-ordered UUIDs
     */
    public function test_v7_generation_passing()
    {
        // Generate multiple v7 UUIDs
        $uuids = [];
        for ($i = 0; $i < 100; $i++) {
            $uuids[] = UUID::v7();
            // Add a small sleep to ensure time progression
            usleep(1000); // 1ms
        }

        // Test that all generated UUIDs are valid
        foreach ($uuids as $uuid) {
            $this->assertTrue(
                UUID::isValid($uuid),
                "UUID {$uuid} is not valid"
            );

            // Test they are all version 7
            $this->assertEquals(
                7,
                UUID::getVersion($uuid),
                "UUID {$uuid} is not version 7"
            );

            // Test specific v7 pattern
            $this->assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
                $uuid,
                "UUID {$uuid} does not match v7 pattern"
            );
        }

        // Check uniqueness
        $this->assertCount(
            count($uuids),
            array_unique($uuids),
            "Duplicate UUIDs were generated"
        );

        // Check time ordering - they should already be in ascending order
        $sorted = $uuids;
        sort($sorted);
        $this->assertEquals(
            $uuids,
            $sorted,
            "UUIDs are not in ascending order"
        );
    }

    /**
     * Test validation of valid UUIDs
     */
    public function test_is_valid_passing()
    {
        // Valid UUIDs
        $this->assertTrue(UUID::isValid('f47ac10b-58cc-4372-a567-0e02b2c3d479'));
        $this->assertTrue(UUID::isValid('F47AC10B-58CC-4372-A567-0E02B2C3D479')); // uppercase

        // Valid with specific version
        $this->assertTrue(UUID::isValid('f47ac10b-58cc-4372-a567-0e02b2c3d479', 4));
        $this->assertTrue(UUID::isValid('f47ac10b-58cc-5372-a567-0e02b2c3d479', 5));
        $this->assertTrue(UUID::isValid('f47ac10b-58cc-7372-a567-0e02b2c3d479', 7));
    }

    /**
     * Test validation of invalid UUIDs
     */
    public function test_is_valid_failing()
    {
        // Invalid UUIDs
        $this->assertFalse(UUID::isValid('not-a-uuid'));
        $this->assertFalse(UUID::isValid('f47ac10b-58cc-X372-a567-0e02b2c3d479')); // invalid char
        $this->assertFalse(UUID::isValid('f47ac10b-58cc-4372-a567-0e02b2c3d47')); // too short
        $this->assertFalse(UUID::isValid('f47ac10b-58cc-4372-a567-0e02b2c3d4799')); // too long
        $this->assertFalse(UUID::isValid('f47ac10b58cc4372a5670e02b2c3d479')); // no hyphens

        // Wrong version validation
        $this->assertFalse(UUID::isValid('f47ac10b-58cc-4372-a567-0e02b2c3d479', 5)); // v4 UUID, v5 validation
        $this->assertFalse(UUID::isValid('f47ac10b-58cc-5372-a567-0e02b2c3d479', 4)); // v5 UUID, v4 validation
    }

    /**
     * Test namespace-based UUID generation for deterministic results
     */
    public function test_v5_deterministic_passing()
    {
        // Test with DNS namespace
        $uuid1 = UUID::v5(UUID::$namespaces['dns'], 'example.com');
        $uuid2 = UUID::v5(UUID::$namespaces['dns'], 'example.com');

        // Check validity
        $this->assertTrue(UUID::isValid($uuid1));
        $this->assertEquals(5, UUID::getVersion($uuid1));

        // Same input should produce same UUID
        $this->assertEquals($uuid1, $uuid2);
    }

    /**
     * Test namespace-based UUID generation produces different UUIDs for different inputs
     */
    public function test_v5_uniqueness_passing()
    {
        // Different domains should produce different UUIDs
        $uuid1 = UUID::v5(UUID::$namespaces['dns'], 'example.com');
        $uuid3 = UUID::v5(UUID::$namespaces['dns'], 'different.com');
        $this->assertNotEquals($uuid1, $uuid3);

        // Different namespaces should produce different UUIDs for same name
        $dnsUuid = UUID::v5(UUID::$namespaces['dns'], 'example.com');
        $urlUuid = UUID::v5(UUID::$namespaces['url'], 'example.com');
        $this->assertNotEquals($dnsUuid, $urlUuid);
    }

    /**
     * Test binary conversion is lossless
     */
    public function test_binary_conversion_passing()
    {
        $uuid = UUID::generate();

        // Convert to binary
        $binary = UUID::toBinary($uuid);

        // Check binary length (16 bytes)
        $this->assertEquals(16, strlen($binary));

        // Convert back from binary
        $recovered = UUID::fromBinary($binary);

        // Check if conversion is lossless
        $this->assertEquals($uuid, $recovered);
    }

    /**
     * Test binary conversion with invalid inputs
     */
    public function test_binary_conversion_failing()
    {
        // Invalid UUIDs should return null
        $this->assertNull(UUID::toBinary('not-a-uuid'));

        // Invalid binary should return null
        $this->assertNull(UUID::fromBinary('too-short'));
        $this->assertNull(UUID::fromBinary(str_repeat('x', 15))); // 15 bytes (too short)
        $this->assertNull(UUID::fromBinary(str_repeat('x', 17))); // 17 bytes (too long)
    }

    /**
     * Test nil UUID generation
     */
    public function test_nil_passing()
    {
        $nil = UUID::nil();

        $this->assertEquals('00000000-0000-0000-0000-000000000000', $nil);
        $this->assertTrue(UUID::isValid($nil), "Nil UUID should be considered valid");
        $this->assertEquals(0, UUID::getVersion($nil), "Nil UUID should return version 0");

        // Nil UUID shouldn't be valid when checking for a specific version
        $this->assertFalse(UUID::isValid($nil, 4), "Nil UUID should not be valid as a specific version");
    }

    /**
     * Test version detection with valid UUIDs
     */
    public function test_get_version_passing()
    {
        // Test with different versions
        $this->assertEquals(4, UUID::getVersion('f47ac10b-58cc-4372-a567-0e02b2c3d479'));
        $this->assertEquals(1, UUID::getVersion('f47ac10b-58cc-1372-a567-0e02b2c3d479'));
        $this->assertEquals(5, UUID::getVersion('f47ac10b-58cc-5372-a567-0e02b2c3d479'));
        $this->assertEquals(7, UUID::getVersion('f47ac10b-58cc-7372-a567-0e02b2c3d479'));
    }

    /**
     * Test version detection with invalid UUIDs
     */
    public function test_get_version_failing()
    {
        // Invalid UUID should return null
        $this->assertNull(UUID::getVersion('not-a-uuid'));
        $this->assertNull(UUID::getVersion('f47ac10b-58cc-x372-a567-0e02b2c3d479'));
        $this->assertNull(UUID::getVersion('f47ac10b58cc4372a5670e02b2c3d479')); // no hyphens
    }

    /**
     * Test generation performance
     */
    public function test_performance_passing()
    {
        $count = 1000;
        $start = microtime(true);

        for ($i = 0; $i < $count; $i++) {
            UUID::generate();
        }

        $duration = microtime(true) - $start;

        // Generation should be fast (less than 5ms per UUID on average)
        $this->assertLessThan(
            5.0,
            ($duration / $count) * 1000,
            "UUID generation is too slow: " . ($duration / $count) * 1000 . "ms per UUID"
        );
    }
}