<?php

namespace Unit\Message;

use Lucent\Http\Message\Uri;
use Lucent\Http\Message\UriResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UriResolverTest extends TestCase
{
    private function resolve(string $base, string $ref): string
    {
        return (string) UriResolver::resolve(Uri::fromString($base), Uri::fromString($ref));
    }

    public function test_relative_path_merges_with_base_directory(): void
    {
        $this->assertSame(
            'https://api.example.com/v1/users',
            $this->resolve('https://api.example.com/v1/', 'users')
        );
    }

    public function test_absolute_path_replaces_base_path(): void
    {
        $this->assertSame(
            'https://api.example.com/users',
            $this->resolve('https://api.example.com/v1/', '/users')
        );
    }

    public function test_dot_segments_removed(): void
    {
        $this->assertSame(
            'https://api.example.com/users',
            $this->resolve('https://api.example.com/v1/', '../users')
        );
    }

    public function test_query_replaces_not_appends(): void
    {
        $this->assertSame(
            'https://api.example.com/v1?page=2',
            $this->resolve('https://api.example.com/v1', '?page=2')
        );
    }

    public function test_fragment_from_relative_uri(): void
    {
        $this->assertSame(
            'https://api.example.com/v1#frag',
            $this->resolve('https://api.example.com/v1', '#frag')
        );
    }

    public function test_absolute_uri_overrides_base(): void
    {
        $this->assertSame(
            'https://other.com/x',
            $this->resolve('https://api.example.com/v1', 'https://other.com/x')
        );
    }

    public function test_scheme_relative_uri_inherits_scheme(): void
    {
        $this->assertSame(
            'https://other.com/x',
            $this->resolve('https://api.example.com/v1', '//other.com/x')
        );
    }

    public function test_empty_relative_uri_returns_base(): void
    {
        $this->assertSame(
            'https://api.example.com/v1',
            $this->resolve('https://api.example.com/v1', '')
        );
    }

    /**
     * RFC 3986 §5.4.1 "Normal Examples" against the reference base
     * http://a/b/c/d;p?q.
     */
    #[DataProvider('rfc3986ExamplesProvider')]
    public function test_rfc3986_reference_examples(string $ref, string $expected): void
    {
        $this->assertSame($expected, $this->resolve('http://a/b/c/d;p?q', $ref));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function rfc3986ExamplesProvider(): array
    {
        return [
            'g:h'     => ['g:h', 'g:h'],
            'g'       => ['g', 'http://a/b/c/g'],
            './g'     => ['./g', 'http://a/b/c/g'],
            'g/'      => ['g/', 'http://a/b/c/g/'],
            '/g'      => ['/g', 'http://a/g'],
            '//g'     => ['//g', 'http://g'],
            '?y'      => ['?y', 'http://a/b/c/d;p?y'],
            'g?y'     => ['g?y', 'http://a/b/c/g?y'],
            '#s'      => ['#s', 'http://a/b/c/d;p?q#s'],
            'g#s'     => ['g#s', 'http://a/b/c/g#s'],
            'g?y#s'   => ['g?y#s', 'http://a/b/c/g?y#s'],
            ';x'      => [';x', 'http://a/b/c/;x'],
            'g;x'     => ['g;x', 'http://a/b/c/g;x'],
            'g;x?y#s' => ['g;x?y#s', 'http://a/b/c/g;x?y#s'],
            ''        => ['', 'http://a/b/c/d;p?q'],
            '.'       => ['.', 'http://a/b/c/'],
            './'      => ['./', 'http://a/b/c/'],
            '..'      => ['..', 'http://a/b/'],
            '../'     => ['../', 'http://a/b/'],
            '../g'    => ['../g', 'http://a/b/g'],
            '../..'   => ['../..', 'http://a/'],
            '../../'  => ['../../', 'http://a/'],
            '../../g' => ['../../g', 'http://a/g'],
        ];
    }
}
