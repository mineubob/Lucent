<?php

namespace Tests\Unit\Message;

use Lucent\Http\Message\RequestContext;
use PHPUnit\Framework\TestCase;

class RequestContextTest extends TestCase
{
    public function test_set_and_get(): void
    {
        $context = new RequestContext();
        $context->set('name', 'John');

        $this->assertSame('John', $context->get('name'));
    }

    public function test_get_returns_default_when_key_missing(): void
    {
        $context = new RequestContext();

        $this->assertSame('fallback', $context->get('missing', 'fallback'));
        $this->assertNull($context->get('missing'));
    }

    public function test_has(): void
    {
        $context = new RequestContext();
        $context->set('name', 'John');

        $this->assertTrue($context->has('name'));
        $this->assertFalse($context->has('missing'));
    }

    public function test_all(): void
    {
        $context = new RequestContext();
        $context->set('name', 'John');
        $context->set('age', 30);

        $this->assertSame(['name' => 'John', 'age' => 30], $context->all());
    }

    public function test_get_typed_returns_value_when_instance_of_class(): void
    {
        $context = new RequestContext();
        $user = new \stdClass();
        $context->set('user', $user);

        $this->assertSame($user, $context->getTyped('user', \stdClass::class));
    }

    public function test_get_typed_returns_default_when_value_is_wrong_type(): void
    {
        $context = new RequestContext();
        $context->set('user', 'not-an-object');

        $this->assertNull($context->getTyped('user', \stdClass::class));
        $this->assertSame('fallback', $context->getTyped('user', \stdClass::class, 'fallback'));
    }

    public function test_get_typed_returns_default_when_key_missing(): void
    {
        $context = new RequestContext();

        $this->assertNull($context->getTyped('missing', \stdClass::class));
        $this->assertSame('fallback', $context->getTyped('missing', \stdClass::class, 'fallback'));
    }

    public function test_get_typed_returns_default_when_value_is_null(): void
    {
        $context = new RequestContext();
        $context->set('user', null);

        $this->assertNull($context->getTyped('user', \stdClass::class));
        $this->assertSame('fallback', $context->getTyped('user', \stdClass::class, 'fallback'));
    }

    public function test_get_typed_with_subclass_instance(): void
    {
        $context = new RequestContext();
        $child = new class extends \stdClass {};
        $context->set('user', $child);

        // A subclass is an instance of the parent class.
        $this->assertSame($child, $context->getTyped('user', \stdClass::class));
    }
}