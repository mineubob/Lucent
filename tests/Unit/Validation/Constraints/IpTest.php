<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Validation\Constraints\Ip;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class IpTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(array $body, Ip $constraint): \Lucent\Validation\Result
    {
        return $this->validateField('ip', $body, $constraint);
    }

    // ─── valid IPs ─────────────────────────────────────────────────────────

    public function test_valid_ipv4_passes(): void
    {
        foreach (['127.0.0.1', '255.255.255.255', '192.168.1.1'] as $ip) {
            $result = $this->validate(['ip' => $ip], new Ip());
            $this->assertFalse($result->hasErrors(), "Expected pass for $ip");
        }
    }

    public function test_valid_ipv6_passes(): void
    {
        foreach (['::1', '2001:db8::1', 'fe80::1'] as $ip) {
            $result = $this->validate(['ip' => $ip], new Ip());
            $this->assertFalse($result->hasErrors(), "Expected pass for $ip");
        }
    }

    // ─── invalid IPs ───────────────────────────────────────────────────────

    public function test_invalid_ipv4_fails(): void
    {
        foreach (['256.0.0.1', '1.2.3', '999.999.999.999'] as $ip) {
            $result = $this->validate(['ip' => $ip], new Ip());
            $this->assertTrue($result->hasErrors(), "Expected fail for $ip");
        }
    }

    public function test_invalid_ipv6_fails(): void
    {
        $result = $this->validate(['ip' => 'gggg::1'], new Ip());

        $this->assertTrue($result->hasErrors());
    }

    // ─── flags ─────────────────────────────────────────────────────────────

    public function test_ipv4_flag_rejects_ipv6(): void
    {
        $result = $this->validate(['ip' => '::1'], new Ip(Ip::IPV4));

        $this->assertTrue($result->hasErrors());
    }

    public function test_ipv6_flag_rejects_ipv4(): void
    {
        $result = $this->validate(['ip' => '127.0.0.1'], new Ip(Ip::IPV6));

        $this->assertTrue($result->hasErrors());
    }

    public function test_ipv4_flag_accepts_ipv4(): void
    {
        $result = $this->validate(['ip' => '127.0.0.1'], new Ip(Ip::IPV4));

        $this->assertFalse($result->hasErrors());
    }

    public function test_ipv6_flag_accepts_ipv6(): void
    {
        $result = $this->validate(['ip' => '::1'], new Ip(Ip::IPV6));

        $this->assertFalse($result->hasErrors());
    }

    // ─── edge cases ────────────────────────────────────────────────────────

    public function test_ip_with_port_fails(): void
    {
        // filter_var with FILTER_VALIDATE_IP does not accept ports.
        $result = $this->validate(['ip' => '127.0.0.1:8080'], new Ip());

        $this->assertTrue($result->hasErrors());
    }

    public function test_empty_string_fails(): void
    {
        $result = $this->validate(['ip' => ''], new Ip());

        $this->assertTrue($result->hasErrors());
    }

    public function test_non_string_types_fail(): void
    {
        foreach ([123, ['127.0.0.1'], null, true] as $value) {
            $result = $this->validate(['ip' => $value], new Ip());
            $this->assertTrue($result->hasErrors(), "Expected fail for " . var_export($value, true));
        }
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message(): void
    {
        $result = $this->validate(['ip' => 'not-an-ip'], new Ip());

        $this->assertSame(
            ['The ip must be a valid IP address.'],
            $result->errors()['ip'],
        );
    }
}