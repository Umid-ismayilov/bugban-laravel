<?php

namespace Bugban\Laravel;

use Bugban\Sdk\Bugban;
use Monolog\Handler\AbstractProcessingHandler;

/**
 * Monolog handler that forwards log records at/above the configured level to Bugban
 * via Bugban::recordLog(). Pushed onto Laravel's default log channel when
 * `capture_logs` is enabled, so Log::error()/critical()/... and caught-and-logged
 * errors reach Bugban instead of only laravel.log.
 *
 * - bubble = true: the record still flows to the file/other handlers, so normal
 *   logging is untouched.
 * - Recursion-safe: Bugban::recordLog() has a static in-progress guard, so if
 *   forwarding a record ever triggers another log the nested call is a no-op.
 * - Double-report mitigation: records that carry a Throwable in context are SKIPPED
 *   here, because BugbanServiceProvider's MessageLogged listener already reports those
 *   as full exceptions (with stacktrace). This keeps an uncaught exception from being
 *   reported twice (once as the exception, once as its log line). Pure Log::error('..')
 *   and caught-and-logged-with-message calls (no Throwable in context) are forwarded.
 *
 * Works with BOTH Monolog 2 (array $record) and Monolog 3 (LogRecord object).
 */
class BugbanLogHandler extends AbstractProcessingHandler
{
    /**
     * @param string $level  Minimum PSR level to forward (e.g. 'error').
     * @param bool   $bubble Must stay true so file logging still happens.
     */
    public function __construct($level = 'error', $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    /**
     * @param array|\Monolog\LogRecord $record
     * @return void
     */
    protected function write($record): void
    {
        try {
            if (is_array($record)) {
                // Monolog 2: associative array.
                $levelName = isset($record['level_name']) ? $record['level_name'] : 'ERROR';
                $message = isset($record['message']) ? $record['message'] : '';
                $context = (isset($record['context']) && is_array($record['context'])) ? $record['context'] : array();
            } else {
                // Monolog 3: LogRecord value object.
                $levelName = 'ERROR';
                if (isset($record->level)) {
                    if (is_object($record->level) && method_exists($record->level, 'getName')) {
                        $levelName = $record->level->getName();
                    } elseif (is_object($record->level) && isset($record->level->name)) {
                        $levelName = $record->level->name;
                    }
                }
                $message = isset($record->message) ? $record->message : '';
                $context = (isset($record->context) && is_array($record->context)) ? $record->context : array();
            }

            // Skip records already handled as exceptions by the MessageLogged listener.
            if (isset($context['exception'])
                && ($context['exception'] instanceof \Throwable || $context['exception'] instanceof \Exception)) {
                return;
            }

            Bugban::recordLog(strtolower((string) $levelName), $message, $context);
        } catch (\Exception $e) {
            // never break the host app
        } catch (\Throwable $e) {
            // non-fatal
        }
    }
}
