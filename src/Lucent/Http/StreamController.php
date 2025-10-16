<?php

namespace Lucent\Http;

use Generator;
use Lucent\Http\EventStream\Event;
use Lucent\Http\EventStream\EventStreamResponse;

abstract class StreamController
{
    abstract protected function stream(): Generator;

    /**
     * Execute the stream
     */
    final public function execute(): EventStreamResponse
    {
        return new EventStreamResponse(function() {
            // CRITICAL: Store initial buffer level to protect existing buffers (like PHPUnit's)
            $initialBufferLevel = ob_get_level();

            // Only disable buffering that was created AFTER this point
            // This prevents closing PHPUnit's or other pre-existing buffers
            while (ob_get_level() > $initialBufferLevel) {
                ob_end_flush();
            }

            set_time_limit(0);
            ignore_user_abort(false);

            try {
                // Send connection event
                Event::data('connected', [
                    'timestamp' => time()
                ])->send();

                // Stream events
                foreach ($this->stream() as $event) {
                    if (!($event instanceof Event)) {
                        throw new \RuntimeException('stream() must yield Event objects');
                    }

                    $event->send();

                    // Check if client disconnected
                    if (connection_aborted()) {
                        break;
                    }
                }

            } catch (\Throwable $e) {
                Event::error($e->getMessage())->send();
            } finally {
                // Send close event
                Event::data('close', [
                    'timestamp' => time()
                ])->send();
            }
        });
    }
}