<?php
/**
 * Minimal in-PHP test runner — used when PHPUnit isn't available.
 *
 * The PHPUnit-style test files in this directory (extends TestCase, uses
 * $this->assertSame, etc.) are the canonical form and run normally on
 * any environment with php-dom + php-mbstring + php-xml + php-xmlwriter
 * installed (i.e. the SPV staging/live servers).
 *
 * On a dev box without those extensions, this runner provides just
 * enough of PHPUnit's surface to execute the same files without
 * modification: a TestCase stub with the assertion methods we actually
 * use, an AssertionFailedError stand-in, and a discovery + dispatcher.
 *
 * Usage:
 *   php tests/run.php                              (run all *Test.php)
 *   php tests/run.php tests/SkuConfigTest.php      (one file)
 *
 * Exit code is 0 if every test method passes, 1 otherwise.
 */

declare(strict_types=1);

// ---- minimal PHPUnit surface --------------------------------------------
// Defined BEFORE the autoloader is loaded so the test files (which extend
// PHPUnit\Framework\TestCase via `use`) see this stub, not the real one
// that needs unavailable PHP extensions.

namespace PHPUnit\Framework {
    class AssertionFailedError extends \RuntimeException {}
    class ExpectationFailedException extends AssertionFailedError {}

    class TestCase
    {
        /** @var array{class:string,exception?:bool} */
        private array $expectedException = ['class' => ''];

        protected function expectException(string $class): void
        {
            $this->expectedException = ['class' => $class];
        }

        public function _hasExpectedException(): bool
        {
            return $this->expectedException['class'] !== '';
        }

        public function _expectedException(): string
        {
            return $this->expectedException['class'];
        }

        public function _resetExpectedException(): void
        {
            $this->expectedException = ['class' => ''];
        }

        protected function assertSame($expected, $actual, string $message = ''): void
        {
            if ($expected !== $actual) {
                throw new AssertionFailedError(
                    $message !== '' ? $message
                                    : sprintf('expected %s, got %s', \json_encode($expected), \json_encode($actual))
                );
            }
        }

        protected function assertEquals($expected, $actual, string $message = ''): void
        {
            if ($expected != $actual) { // intentional loose equality
                throw new AssertionFailedError(
                    $message !== '' ? $message
                                    : sprintf('expected (==) %s, got %s', \json_encode($expected), \json_encode($actual))
                );
            }
        }

        protected function assertTrue($condition, string $message = ''): void
        {
            if ($condition !== true) {
                throw new AssertionFailedError($message !== '' ? $message : 'expected true');
            }
        }

        protected function assertFalse($condition, string $message = ''): void
        {
            if ($condition !== false) {
                throw new AssertionFailedError($message !== '' ? $message : 'expected false');
            }
        }

        protected function assertNull($value, string $message = ''): void
        {
            if ($value !== null) {
                throw new AssertionFailedError(
                    $message !== '' ? $message
                                    : sprintf('expected null, got %s', \json_encode($value))
                );
            }
        }

        protected function assertNotNull($value, string $message = ''): void
        {
            if ($value === null) {
                throw new AssertionFailedError($message !== '' ? $message : 'expected non-null');
            }
        }
    }
}

// ---- runner -------------------------------------------------------------

namespace {
    require_once __DIR__ . '/../vendor/autoload.php';

    function tk_color(string $text, string $code): string
    {
        return STDOUT && function_exists('posix_isatty') && @posix_isatty(STDOUT)
            ? "\033[{$code}m{$text}\033[0m"
            : $text;
    }

    function tk_red(string $s): string    { return tk_color($s, '31'); }
    function tk_green(string $s): string  { return tk_color($s, '32'); }
    function tk_yellow(string $s): string { return tk_color($s, '33'); }

    function tk_discover_classes(string $file): array
    {
        $src = file_get_contents($file);
        if ($src === false) return [];
        $ns  = '';
        if (preg_match('/^\s*namespace\s+([^;]+);/m', $src, $m)) $ns = trim($m[1]);
        if (!preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $m)) return [];
        $classes = [];
        foreach ($m[1] as $name) {
            $classes[] = $ns !== '' ? $ns . '\\' . $name : $name;
        }
        return $classes;
    }

    /** @return array{passed:int,failed:int,messages:list<string>} */
    function tk_run_class(string $class): array
    {
        if (!class_exists($class)) {
            return ['passed' => 0, 'failed' => 1, 'messages' => ["class $class did not load"]];
        }
        if (!is_subclass_of($class, \PHPUnit\Framework\TestCase::class)) {
            return ['passed' => 0, 'failed' => 0, 'messages' => []];
        }
        $passed = 0;
        $failed = 0;
        $messages = [];
        $methods = get_class_methods($class) ?: [];
        foreach ($methods as $method) {
            if (!str_starts_with($method, 'test_') && !str_starts_with($method, 'test')) continue;
            // Anything starting with 'test' that's not abstract / not on TestCase itself.
            $rm = new \ReflectionMethod($class, $method);
            if ($rm->getDeclaringClass()->getName() === \PHPUnit\Framework\TestCase::class) continue;
            $instance = new $class();
            // Mirror PHPUnit's setUp/tearDown lifecycle. The stub
            // TestCase doesn't declare them, so we sniff via
            // method_exists on the concrete subclass.
            $hasSetUp    = method_exists($instance, 'setUp');
            $hasTearDown = method_exists($instance, 'tearDown');
            try {
                if ($hasSetUp) {
                    (function () { $this->setUp(); })->call($instance);
                }
                $instance->$method();
                if ($instance->_hasExpectedException()) {
                    $expected = $instance->_expectedException();
                    $failed++;
                    $messages[] = "$class::$method  FAIL  expected $expected but none thrown";
                    continue;
                }
                $passed++;
            } catch (\PHPUnit\Framework\AssertionFailedError $e) {
                $failed++;
                $messages[] = "$class::$method  FAIL  " . $e->getMessage();
            } catch (\Throwable $t) {
                if ($instance->_hasExpectedException() && is_a($t, $instance->_expectedException())) {
                    $passed++;
                } else {
                    $failed++;
                    $messages[] = "$class::$method  ERROR  " . get_class($t) . ': ' . $t->getMessage();
                }
            } finally {
                if ($hasTearDown) {
                    try {
                        (function () { $this->tearDown(); })->call($instance);
                    } catch (\Throwable $tdEx) {
                        // Don't let tearDown failures mask the original
                        // outcome; just surface them as messages.
                        $messages[] = "$class::$method  TEARDOWN  " . $tdEx->getMessage();
                    }
                }
                $instance->_resetExpectedException();
            }
        }
        return ['passed' => $passed, 'failed' => $failed, 'messages' => $messages];
    }

    $argv_in = $argv ?? [];
    array_shift($argv_in); // script name
    $files = [];
    if (count($argv_in) === 0) {
        foreach (glob(__DIR__ . '/*Test.php') as $f) $files[] = $f;
    } else {
        foreach ($argv_in as $a) $files[] = $a;
    }

    $total_passed = 0;
    $total_failed = 0;
    foreach ($files as $file) {
        echo "\n" . tk_yellow('[' . basename($file) . ']') . "\n";
        require_once $file;
        foreach (tk_discover_classes($file) as $class) {
            $r = tk_run_class($class);
            $total_passed += $r['passed'];
            $total_failed += $r['failed'];
            foreach ($r['messages'] as $msg) {
                echo '  ' . tk_red('FAIL') . " $msg\n";
            }
            if ($r['failed'] === 0 && $r['passed'] > 0) {
                echo '  ' . tk_green($r['passed'] . ' passed') . " ($class)\n";
            }
        }
    }

    echo "\n";
    if ($total_failed === 0) {
        echo tk_green("== {$total_passed} assertions passed ==") . "\n";
        exit(0);
    }
    echo tk_red("== {$total_passed} passed, {$total_failed} failed ==") . "\n";
    exit(1);
}
