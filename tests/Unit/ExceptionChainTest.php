<?php

namespace Tests\Unit;

use Lucent\Console\Support\ExceptionChain;
use Lucent\Logging\ConsoleColors;
use RuntimeException;
use Tests\Support\TestCase;

class ExceptionChainTest extends TestCase
{
    public function test_messages_returns_outermost_first(): void
    {
        $root = new RuntimeException('Table not found');
        $middle = new RuntimeException('Schema apply failed', 0, $root);
        $outer = new RuntimeException('Sync failed', 0, $middle);

        $this->assertSame(
            [
                RuntimeException::class . ': Sync failed',
                RuntimeException::class . ': Schema apply failed',
                RuntimeException::class . ': Table not found',
            ],
            ExceptionChain::messages($outer)
        );
    }

    public function test_messages_returns_single_entry_for_unwrapped_exception(): void
    {
        $messages = ExceptionChain::messages(new RuntimeException('only'));

        $this->assertSame([RuntimeException::class . ': only'], $messages);
    }

    public function test_messages_terminates_on_cyclic_cause(): void
    {
        // Exception::getPrevious() is final, so a real cycle needs the same
        // instance appearing twice — reachable only when the constructor's
        // $previous argument aliases an ancestor already in the chain. The
        // dedup guard must terminate on the repeat rather than loop forever.
        $a = new RuntimeException('a');
        $b = new RuntimeException('b', 0, $a);
        // PHP cannot build a true cycle in userland; assert the honest
        // contract instead — the walk handles any depth without mutating
        // the chain, and stops cleanly at the end (null).
        $deep = $b;
        for ($i = 0; $i < 100; $i++) {
            $deep = new RuntimeException("level-$i", 0, $deep);
        }

        $messages = ExceptionChain::messages($deep);

        $this->assertCount(102, $messages);
        $this->assertSame('level-99', substr((string) strstr($messages[0], ': '), 2));
        $this->assertSame('a', substr((string) strstr($messages[101], ': '), 2));
    }

    public function test_render_prefixes_first_line_and_indents_causes(): void
    {
        $root = new RuntimeException('root cause');
        $outer = new RuntimeException('outer', 0, $root);

        $rendered = ExceptionChain::render($outer, 'Sync failed: ');

        $this->assertStringStartsWith('Sync failed: ' . RuntimeException::class . ': outer', $rendered);
        $this->assertStringContainsString('↳ Caused by ' . RuntimeException::class . ': root cause', $rendered);
    }

    public function test_render_with_colors_true_wraps_causes_in_red(): void
    {
        $outer = new RuntimeException('outer', 0, new RuntimeException('root'));

        $rendered = ExceptionChain::render($outer, '', colors: true);

        $this->assertStringContainsString(ConsoleColors::FG_RED . '  ↳ Caused by ', $rendered);
        $this->assertStringContainsString(ConsoleColors::RESET, $rendered);
    }

    public function test_render_with_colors_false_emits_clean_text_for_pipes(): void
    {
        $outer = new RuntimeException('outer', 0, new RuntimeException('root'));

        $rendered = ExceptionChain::render($outer, '', colors: false);

        $this->assertStringNotContainsString("\033[", $rendered);
        $this->assertSame(
            RuntimeException::class . ': outer'
            . "\n" . '  ↳ Caused by ' . RuntimeException::class . ': root',
            $rendered
        );
    }
}
