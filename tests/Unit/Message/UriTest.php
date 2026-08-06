<?php

namespace Unit\Message;

use Lucent\Http\Message\Uri;
use PHPUnit\Framework\TestCase;

class UriTest extends TestCase
{
    public function test_from_string_parses_full_uri(): void
    {
        $uri = Uri::fromString('https://user:pass@example.com:8080/path?query=value#fragment');

        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('user:pass', $uri->getUserInfo());
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame(8080, $uri->getPort());
        $this->assertSame('/path', $uri->getPath());
        $this->assertSame('query=value', $uri->getQuery());
        $this->assertSame('fragment', $uri->getFragment());
    }

    public function test_from_string_returns_default_for_empty(): void
    {
        $uri = Uri::fromString('');
        $this->assertSame('', $uri->getScheme());
        $this->assertSame('', $uri->getHost());
        $this->assertSame('', $uri->getPath());
    }

    public function test_get_authority_includes_port(): void
    {
        $uri = Uri::fromString('https://user:pass@example.com:8080/path');
        $this->assertSame('user:pass@example.com:8080', $uri->getAuthority());
    }

    public function test_get_authority_omits_standard_port(): void
    {
        $uri = Uri::fromString('https://example.com:443/path');
        $this->assertSame('example.com:443', $uri->getAuthority());
    }

    public function test_get_authority_omits_user_info_when_empty(): void
    {
        $uri = Uri::fromString('https://example.com/path');
        $this->assertSame('example.com', $uri->getAuthority());
    }

    public function test_with_scheme_returns_new_instance(): void
    {
        $uri = Uri::fromString('http://example.com');
        $new = $uri->withScheme('https');

        $this->assertSame('http', $uri->getScheme());
        $this->assertSame('https', $new->getScheme());
    }

    public function test_with_scheme_lowercases(): void
    {
        $uri = Uri::fromString('http://example.com')->withScheme('HTTPS');
        $this->assertSame('https', $uri->getScheme());
    }

    public function test_with_user_info(): void
    {
        $uri = Uri::fromString('http://example.com');
        $new = $uri->withUserInfo('user', 'pass');

        $this->assertSame('user:pass', $new->getUserInfo());
    }

    public function test_with_user_info_no_password(): void
    {
        $uri = Uri::fromString('http://example.com')->withUserInfo('user');
        $this->assertSame('user', $uri->getUserInfo());
    }

    public function test_with_host(): void
    {
        $uri = Uri::fromString('http://example.com');
        $new = $uri->withHost('other.com');

        $this->assertSame('other.com', $new->getHost());
        $this->assertSame('example.com', $uri->getHost());
    }

    public function test_with_port(): void
    {
        $uri = Uri::fromString('http://example.com');
        $new = $uri->withPort(8080);

        $this->assertSame(8080, $new->getPort());
        $this->assertNull($uri->getPort());
    }

    public function test_with_port_null_removes_port(): void
    {
        $uri = Uri::fromString('http://example.com:8080')->withPort(null);
        $this->assertNull($uri->getPort());
    }

    public function test_with_port_standard_becomes_null(): void
    {
        $uri = Uri::fromString('http://example.com')->withPort(80);
        $this->assertNull($uri->getPort());
    }

    public function test_with_path(): void
    {
        $uri = Uri::fromString('http://example.com');
        $new = $uri->withPath('/new/path');

        $this->assertSame('/new/path', $new->getPath());
    }

    public function test_with_query(): void
    {
        $uri = Uri::fromString('http://example.com');
        $new = $uri->withQuery('key=value');

        $this->assertSame('key=value', $new->getQuery());
    }

    public function test_with_query_strips_leading_question_mark(): void
    {
        $uri = Uri::fromString('http://example.com')->withQuery('?key=value');
        $this->assertSame('key=value', $uri->getQuery());
    }

    public function test_with_fragment(): void
    {
        $uri = Uri::fromString('http://example.com');
        $new = $uri->withFragment('section');

        $this->assertSame('section', $new->getFragment());
    }

    public function test_to_string_reconstructs_uri(): void
    {
        $uri = Uri::fromString('https://example.com:8080/path?q=1#frag');
        $this->assertSame('https://example.com:8080/path?q=1#frag', (string) $uri);
    }

    public function test_to_string_without_authority(): void
    {
        $uri = Uri::fromString('/path?q=1');
        $this->assertSame('/path?q=1', (string) $uri);
    }

    public function test_from_globals_creates_uri_from_server(): void
    {
        $server = [
            'HTTPS' => 'on',
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '443',
            'REQUEST_URI' => '/path?q=1',
        ];

        $uri = Uri::fromGlobals($server);
        $this->assertSame('https', $uri->getScheme());
        $this->assertSame('example.com', $uri->getHost());
        $this->assertSame('/path', $uri->getPath());
        $this->assertSame('q=1', $uri->getQuery());
    }

    public function test_from_globals_defaults_to_http(): void
    {
        $server = [
            'HTTP_HOST' => 'example.com',
            'SERVER_PORT' => '80',
            'REQUEST_URI' => '/',
        ];

        $uri = Uri::fromGlobals($server);
        $this->assertSame('http', $uri->getScheme());
        $this->assertSame('', $uri->getQuery());
    }

    public function test_from_globals_handles_no_host(): void
    {
        $uri = Uri::fromGlobals([]);
        $this->assertSame('', $uri->getHost());
        $this->assertSame('/', $uri->getPath());
        $this->assertSame('', $uri->getQuery());
    }

    public function test_with_port_accepts_any_valid_range(): void
    {
        $uri = Uri::fromString('http://example.com')->withPort(99999);
        $this->assertSame(99999, $uri->getPort());
    }

    public function test_with_port_negative_sets_negative_port(): void
    {
        $uri = Uri::fromString('http://example.com')->withPort(-1);
        $this->assertSame(-1, $uri->getPort());
    }
}