<?php
declare(strict_types=1);


namespace Lucent\Http\Message;

use Psr\Http\Message\UriInterface;

/**
 * Resolves URI references against a base URI per RFC 3986 §5.2.
 *
 * Used to merge a configured `base_uri` with per-request relative URIs.
 * Operates purely on {@see UriInterface} objects — callers parse strings
 * with {@see Uri::fromString()} before calling {@see resolve()}.
 *
 * This is the standard RFC 3986 approach: PSR-7's UriInterface has no
 * resolution method (only getters + `with*` mutators), so the algorithm
 * lives in an external helper.
 */
final class UriResolver
{
    /**
     * Resolve a URI reference against a base URI.
     *
     * The result is built by cloning `$rel` and applying `with*` mutators
     * conditionally — no new URI is ever constructed from scratch.
     *
     * @param UriInterface $base The base URI (e.g. the configured `base_uri`)
     * @param UriInterface $rel  The URI reference to resolve (e.g. the request URI)
     */
    public static function resolve(UriInterface $base, UriInterface $rel): UriInterface
    {
        // §5.2.2: if the reference has a scheme, it is an absolute URI and
        // overrides the base entirely.
        if ($rel->getScheme() !== '') {
            return $rel->withPath(self::removeDotSegments($rel->getPath()));
        }

        $result = $rel;

        // Network-path reference ("//host/path") — inherit the scheme from
        // the base, but keep the reference's own authority.
        if ($rel->getHost() !== '') {
            return $result
                ->withScheme($base->getScheme())
                ->withPath(self::removeDotSegments($rel->getPath()))
                ->withQuery($rel->getQuery());
        }

        // Relative reference — inherit scheme and authority from the base.
        $result = $result
            ->withScheme($base->getScheme())
            ->withUserInfo($base->getUserInfo())
            ->withHost($base->getHost())
            ->withPort($base->getPort());

        // §5.2.2: empty path — take the base path; query from the reference
        // if present, else from the base. Fragment always comes from $rel
        // (preserved by cloning).
        if ($rel->getPath() === '') {
            return $result
                ->withPath($base->getPath())
                ->withQuery($rel->getQuery() !== '' ? $rel->getQuery() : $base->getQuery());
        }

        // Absolute path ("/x") — use as-is; rootless path ("x") — merge
        // with the base's directory.
        if ($rel->getPath()[0] === '/') {
            $targetPath = self::removeDotSegments($rel->getPath());
        } else {
            $targetPath = self::removeDotSegments(self::mergePaths($base, $rel->getPath()));
        }

        return $result
            ->withPath($targetPath)
            ->withQuery($rel->getQuery());
    }

    /**
     * §5.2.3: merge a rootless reference path with the base path.
     *
     * If the base has an authority and an empty path, the result is "/" + R.path.
     * Otherwise, take the base path up to and including its rightmost "/"
     * (i.e. strip the last segment) and append R.path.
     */
    private static function mergePaths(UriInterface $base, string $relPath): string
    {
        $basePath = $base->getPath();

        if ($base->getHost() !== '' && $basePath === '') {
            return '/' . $relPath;
        }

        $lastSlash = strrpos($basePath, '/');
        if ($lastSlash === false) {
            return $relPath;
        }

        return substr($basePath, 0, $lastSlash + 1) . $relPath;
    }

    /**
     * §5.2.4: remove dot segments ("." and "..") from a path.
     *
     * Implements the RFC 3986 algorithm directly.
     */
    private static function removeDotSegments(string $path): string
    {
        $output = '';

        while ($path !== '') {
            if (str_starts_with($path, '../')) {
                $path = substr($path, 3);
            } elseif (str_starts_with($path, './')) {
                $path = substr($path, 2);
            } elseif (str_starts_with($path, '/./')) {
                $path = '/' . substr($path, 3);
            } elseif ($path === '/.') {
                $path = '/';
            } elseif (str_starts_with($path, '/../')) {
                $path = '/' . substr($path, 4);
                $output = self::removeLastSegment($output);
            } elseif ($path === '/..') {
                $path = '/';
                $output = self::removeLastSegment($output);
            } elseif ($path === '.' || $path === '..') {
                $path = '';
            } else {
                // Move the first path segment (including its leading "/"
                // if any) to the output buffer.
                $next = strpos($path, '/', 1);
                if ($next === false) {
                    $segment = $path;
                    $path = '';
                } else {
                    $segment = substr($path, 0, $next);
                    $path = substr($path, $next);
                }
                $output .= $segment;
            }
        }

        return $output;
    }

    /**
     * Remove the last path segment and its preceding "/" from the output buffer.
     */
    private static function removeLastSegment(string $output): string
    {
        $pos = strrpos($output, '/');
        if ($pos === false) {
            return '';
        }

        return substr($output, 0, $pos);
    }
}
