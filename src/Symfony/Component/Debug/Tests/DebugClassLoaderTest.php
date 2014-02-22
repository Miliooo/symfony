<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Debug\Tests;

use Symfony\Component\Debug\DebugClassLoader;
use Symfony\Component\Debug\ErrorHandler;

class DebugClassLoaderTest extends \PHPUnit_Framework_TestCase
{
    /**
     * @var int Error reporting level before running tests.
     */
    private $errorReporting;

    private $loader;

    protected function setUp()
    {
        $this->errorReporting = error_reporting(E_ALL | E_STRICT);
        $this->loader = new ClassLoader();
        spl_autoload_register(array($this->loader, 'loadClass'));
        DebugClassLoader::enable();
    }

    protected function tearDown()
    {
        DebugClassLoader::disable();
        spl_autoload_unregister(array($this->loader, 'loadClass'));
        error_reporting($this->errorReporting);
    }

    public function testIdempotence()
    {
        DebugClassLoader::enable();

        $functions = spl_autoload_functions();
        foreach ($functions as $function) {
            if (is_array($function) && $function[0] instanceof DebugClassLoader) {
                $reflClass = new \ReflectionClass($function[0]);
                $reflProp = $reflClass->getProperty('classLoader');
                $reflProp->setAccessible(true);

                $this->assertNotInstanceOf('Symfony\Component\Debug\DebugClassLoader', $reflProp->getValue($function[0]));

                return;
            }
        }

        $this->fail('DebugClassLoader did not register');
    }

    public function testUnsilencing()
    {
        ob_start();
        $bak = array(
            ini_set('log_errors', 0),
            ini_set('display_errors', 1),
        );

        // See below: this will fail with parse error
        // but this should not be @-silenced.
        @ class_exists(__NAMESPACE__.'\TestingUnsilencing', true);

        ini_set('log_errors', $bak[0]);
        ini_set('display_errors', $bak[1]);
        $output = ob_get_clean();

        $this->assertStringMatchesFormat('%aParse error%a', $output);
    }

    /**
     * @expectedException \Symfony\Component\Debug\Exception\DummyException
     */
    public function testStacking()
    {
        // the ContextErrorException must not be loaded to test the workaround
        // for https://bugs.php.net/65322.
        if (class_exists('Symfony\Component\Debug\Exception\ContextErrorException', false)) {
            $this->markTestSkipped('The ContextErrorException class is already loaded.');
        }

        $exceptionHandler = $this->getMock('Symfony\Component\Debug\ExceptionHandler', array('handle'));
        set_exception_handler(array($exceptionHandler, 'handle'));

        $that = $this;
        $exceptionCheck = function ($exception) use ($that) {
            $that->assertInstanceOf('Symfony\Component\Debug\Exception\ContextErrorException', $exception);
            $that->assertEquals(E_STRICT, $exception->getSeverity());
            $that->assertStringStartsWith(__FILE__, $exception->getFile());
            $that->assertRegexp('/^Runtime Notice: Declaration/', $exception->getMessage());
        };

        $exceptionHandler->expects($this->once())
            ->method('handle')
            ->will($this->returnCallback($exceptionCheck));
        ErrorHandler::register();

        try {
            // Trigger autoloading + E_STRICT at compile time
            // which in turn triggers $errorHandler->handle()
            // that again triggers autoloading for ContextErrorException.
            // Error stacking works around the bug above and everything is fine.

            eval('
                namespace '.__NAMESPACE__.';
                class ChildTestingStacking extends TestingStacking { function foo($bar) {} }
            ');
        } catch (\Exception $e) {
            restore_error_handler();
            restore_exception_handler();

            throw $e;
        }

        restore_error_handler();
        restore_exception_handler();
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testNameCaseMismatch()
    {
        class_exists(__NAMESPACE__.'\TestingCaseMismatch', true);
    }

    /**
     * @expectedException \RuntimeException
     */
    public function testFileCaseMismatch()
    {
        class_exists(__NAMESPACE__.'\Fixtures\CaseMismatch', true);
    }
}

class ClassLoader
{
    public function loadClass($class)
    {
    }

    public function findFile($class)
    {
        if (__NAMESPACE__.'\TestingUnsilencing' === $class) {
            eval('-- parse error --');
        } elseif (__NAMESPACE__.'\TestingStacking' === $class) {
            eval('namespace '.__NAMESPACE__.'; class TestingStacking { function foo() {} }');
        } elseif (__NAMESPACE__.'\TestingCaseMismatch' === $class) {
            eval('namespace '.__NAMESPACE__.'; class TestingCaseMisMatch {}');
        } elseif (__NAMESPACE__.'\Fixtures\CaseMismatch' === $class) {
            return __DIR__ . '/Fixtures/casemismatch.php';
        }
    }
}