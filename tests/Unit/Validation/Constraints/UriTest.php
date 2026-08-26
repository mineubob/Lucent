<?php

namespace Tests\Unit\Validation\Constraints;

use Lucent\Http\Message\Uri as MessageUri;
use Lucent\Validation\Constraints\Uri;
use PHPUnit\Framework\TestCase;
use Tests\Support\Concerns\BuildsValidationRequests;

class UriTest extends TestCase
{
    use BuildsValidationRequests;

    private function validate(array $body, Uri $constraint): \Lucent\Validation\Result
    {
        return $this->validateField('uri', $body, $constraint);
    }

    // ─── valid URIs ────────────────────────────────────────────────────────

    public function test_valid_absolute_uri_passes(): void
    {
        $result = $this->validate(['uri' => 'https://example.com/path'], new Uri());

        $this->assertFalse($result->hasErrors());
    }

    public function test_valid_uri_normalizes_to_message_uri(): void
    {
        $result = $this->validate(['uri' => 'https://example.com/path'], new Uri());

        $value = $result->value('uri');
        $this->assertInstanceOf(MessageUri::class, $value);
        $this->assertSame('https', $value->getScheme());
        $this->assertSame('example.com', $value->getHost());
        $this->assertSame('/path', $value->getPath());
    }

    public function test_uri_with_query_string_passes(): void
    {
        $result = $this->validate(['uri' => 'https://example.com/path?key=value'], new Uri());

        $this->assertFalse($result->hasErrors());
        $this->assertSame('key=value', $result->value('uri')->getQuery());
    }

    public function test_uri_with_fragment_passes(): void
    {
        $result = $this->validate(['uri' => 'https://example.com/path#section'], new Uri());

        $this->assertFalse($result->hasErrors());
        $this->assertSame('section', $result->value('uri')->getFragment());
    }

    // ─── invalid URIs ──────────────────────────────────────────────────────

    public function test_invalid_uri_fails(): void
    {
        $result = $this->validate(['uri' => 'not a uri with spaces'], new Uri());

        $this->assertTrue($result->hasErrors());
    }

    public function test_invalid_host_fails(): void
    {
        $result = $this->validate(['uri' => 'http://-invalid-/'], new Uri());

        $this->assertTrue($result->hasErrors());
    }

    public function test_ipv4_host_passes(): void
    {
        $result = $this->validate(['uri' => 'http://127.0.0.1/path'], new Uri());

        $this->assertFalse($result->hasErrors());
    }

    public function test_ipv6_bracketed_host_passes(): void
    {
        $result = $this->validate(['uri' => 'http://[::1]/path'], new Uri());

        $this->assertFalse($result->hasErrors());
    }

    /**
     * BUG-REVEALING TEST: Uri::isValid() intends to reject control characters,
     * but parse_url() converts them to '_' before the regex check runs, so the
     * control-char rejection never triggers and the URI passes.
     */
    public function test_control_char_rejected(): void
    {
        $result = $this->validate(['uri' => "https://example.com/\x00"], new Uri());

        $this->assertTrue($result->hasErrors());
    }

    public function test_out_of_range_port_rejected(): void
    {
        $result = $this->validate(['uri' => 'https://example.com:99999'], new Uri());

        $this->assertTrue($result->hasErrors());
    }

    public function test_valid_port_passes(): void
    {
        $result = $this->validate(['uri' => 'https://example.com:8080'], new Uri());

        $this->assertFalse($result->hasErrors());
    }

    // ─── flags ─────────────────────────────────────────────────────────────

    public function test_mailto_fails_with_default_flags(): void
    {
        // mailto: has no host, so VALIDATE_DEFAULT (which requires a host) fails.
        $result = $this->validate(['uri' => 'mailto:test@example.com'], new Uri());

        $this->assertTrue($result->hasErrors());
    }

    public function test_mailto_passes_with_absolute_only(): void
    {
        // With only VALIDATE_ABSOLUTE (no host requirement), mailto passes.
        $result = $this->validate(['uri' => 'mailto:test@example.com'], new Uri(MessageUri::VALIDATE_ABSOLUTE));

        $this->assertFalse($result->hasErrors());
    }

    public function test_mailto_fails_with_strict_flags(): void
    {
        $result = $this->validate(['uri' => 'mailto:test@example.com'], new Uri(MessageUri::VALIDATE_STRICT));

        $this->assertTrue($result->hasErrors());
    }

    public function test_strict_rejects_non_http_scheme(): void
    {
        $result = $this->validate(['uri' => 'ftp://example.com/file'], new Uri(MessageUri::VALIDATE_STRICT));

        $this->assertTrue($result->hasErrors());
    }

    public function test_strict_requires_host(): void
    {
        // VALIDATE_STRICT alone still requires a host.
        $result = $this->validate(['uri' => 'https://'], new Uri(MessageUri::VALIDATE_STRICT));

        $this->assertTrue($result->hasErrors());
    }

    public function test_strict_accepts_https(): void
    {
        $result = $this->validate(['uri' => 'https://example.com'], new Uri(MessageUri::VALIDATE_STRICT));

        $this->assertFalse($result->hasErrors());
    }

    /**
     * BUG-REVEALING TEST: VALIDATE_RELATIVE is declared but never checked in
     * Uri::isValid(). A relative URI is accepted by default (flags=0), so this
     * test asserting the flag accepts a relative URI may pass regardless.
     */
    public function test_relative_uri_with_relative_flag(): void
    {
        $result = $this->validate(['uri' => '/users/123'], new Uri(MessageUri::VALIDATE_RELATIVE));

        $this->assertFalse($result->hasErrors());
    }

    // ─── edge cases ────────────────────────────────────────────────────────

    public function test_empty_string_fails(): void
    {
        $result = $this->validate(['uri' => ''], new Uri());

        $this->assertTrue($result->hasErrors());
    }

    public function test_non_string_types_fail(): void
    {
        foreach ([123, ['https://example.com'], null, true] as $value) {
            $result = $this->validate(['uri' => $value], new Uri());
            $this->assertTrue($result->hasErrors(), "Expected fail for " . var_export($value, true));
        }
    }

    // ─── message ───────────────────────────────────────────────────────────

    public function test_error_message(): void
    {
        $result = $this->validate(['uri' => 'not a uri'], new Uri());

        $this->assertSame(
            ['The uri must be a valid URI.'],
            $result->errors()['uri'],
        );
    }
}