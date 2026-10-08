<?php

declare(strict_types=1);

namespace Lucent\Console\Support;

use Lucent\Logging\ConsoleColors;

/**
 * Renders a throwable's full cause chain for console error output.
 *
 * Wrapping exceptions (container, metadata, PDO…) flatten to their innermost
 * message when only getMessage() is printed — the context of what actually
 * failed is lost. This walks the chain via getPrevious() so every link is
 * visible, outermost first.
 */
final class ExceptionChain
{
    /**
     * The chain as "Class: message" strings, outermost first.
     *
     * Exception::getPrevious() is final, so userland cannot build a cycle;
     * the visited-set is a defensive guard anyway (a native or exotic
     * Throwable implementation could) and costs nothing per link.
     *
     * @return list<string>
     */
    public static function messages(\Throwable $e): array
    {
        $messages = [];
        $seen = [];

        for ($current = $e; $current !== null; $current = $current->getPrevious()) {
            $hash = spl_object_id($current);

            if (isset($seen[$hash])) {
                break;
            }

            $seen[$hash] = true;
            $messages[] = $current::class . ': ' . $current->getMessage();
        }

        return $messages;
    }

    /**
     * Render the chain as console lines: the first line carries the caller's
     * prefix (already styled by the caller), each cause follows as an
     * indented "Caused by" line in red.
     *
     * @param string $firstLine e.g. "Sync failed: " — the outermost message is appended
     * @return string Multi-line string without a trailing newline
     */
    public static function render(\Throwable $e, string $firstLine): string
    {
        $messages = self::messages($e);

        $output = $firstLine . array_shift($messages);

        foreach ($messages as $message) {
            $output .= "\n" . ConsoleColors::FG_RED
                . '  ↳ Caused by ' . $message
                . ConsoleColors::RESET;
        }

        return $output;
    }
}
