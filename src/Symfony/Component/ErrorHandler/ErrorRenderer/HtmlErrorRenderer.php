<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\ErrorHandler\ErrorRenderer;

use Psr\Log\LoggerInterface;
use Symfony\Component\ErrorHandler\Exception\FlattenException;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Debug\FileLinkFormatter;
use Symfony\Component\HttpKernel\Log\DebugLoggerInterface;

/**
 * @author Yonel Ceruto <yonelceruto@gmail.com>
 */
class HtmlErrorRenderer implements ErrorRendererInterface
{
    private $debug;
    private $charset;
    private $fileLinkFormat;
    private $projectDir;
    private $outputBuffer;
    private $logger;

    private static $template = 'views/error.html.php';

    /**
     * @param bool|callable                 $debug          The debugging mode as a boolean or a callable that should return it
     * @param string|FileLinkFormatter|null $fileLinkFormat
     * @param bool|callable                 $outputBuffer   The output buffer as a string or a callable that should return it
     */
    public function __construct($debug = false, string $charset = null, $fileLinkFormat = null, string $projectDir = null, $outputBuffer = '', LoggerInterface $logger = null)
    {
        if (!\is_bool($debug) && !\is_callable($debug)) {
            throw new \TypeError(sprintf('Argument 1 passed to "%s()" must be a boolean or a callable, "%s" given.', __METHOD__, \gettype($debug)));
        }

        if (!\is_string($outputBuffer) && !\is_callable($outputBuffer)) {
            throw new \TypeError(sprintf('Argument 5 passed to "%s()" must be a string or a callable, "%s" given.', __METHOD__, \gettype($outputBuffer)));
        }

        $this->debug = $debug;
        $this->charset = $charset ?: (\ini_get('default_charset') ?: 'UTF-8');
        $this->fileLinkFormat = $fileLinkFormat ?: (\ini_get('xdebug.file_link_format') ?: get_cfg_var('xdebug.file_link_format'));
        $this->projectDir = $projectDir;
        $this->outputBuffer = $outputBuffer;
        $this->logger = $logger;
    }

    /**
     * {@inheritdoc}
     */
    public function render(\Throwable $exception): FlattenException
    {
        $headers = ['Content-Type' => 'text/html; charset='.$this->charset];
        if (\is_bool($this->debug) ? $this->debug : ($this->debug)($exception)) {
            $headers['X-Debug-Exception'] = rawurlencode($exception->getMessage());
            $headers['X-Debug-Exception-File'] = rawurlencode($exception->getFile()).':'.$exception->getLine();
        }

        $exception = FlattenException::createFromThrowable($exception, null, $headers);

        return $exception->setAsString($this->renderException($exception));
    }

    /**
     * Gets the HTML content associated with the given exception.
     */
    public function getBody(FlattenException $exception): string
    {
        return $this->renderException($exception, 'views/exception.html.php');
    }

    /**
     * Gets the stylesheet associated with the given exception.
     */
    public function getStylesheet(): string
    {
        if (!$this->debug) {
            return $this->include('assets/css/error.css');
        }

        return $this->include('assets/css/exception.css');
    }

    public static function isDebug(RequestStack $requestStack, bool $debug): \Closure
    {
        return static function () use ($requestStack, $debug): bool {
            if (!$request = $requestStack->getCurrentRequest()) {
                return $debug;
            }

            return $debug && $request->attributes->getBoolean('showException', true);
        };
    }

    public static function getAndCleanOutputBuffer(RequestStack $requestStack): \Closure
    {
        return static function () use ($requestStack): string {
            if (!$request = $requestStack->getCurrentRequest()) {
                return '';
            }

            $startObLevel = $request->headers->get('X-Php-Ob-Level', -1);

            if (ob_get_level() <= $startObLevel) {
                return '';
            }

            Response::closeOutputBuffers($startObLevel + 1, true);

            return ob_get_clean();
        };
    }

    private function renderException(FlattenException $exception, string $debugTemplate = 'views/exception_full.html.php'): string
    {
        $debug = \is_bool($this->debug) ? $this->debug : ($this->debug)($exception);
        $statusText = $this->escape($exception->getStatusText());
        $statusCode = $this->escape($exception->getStatusCode());

        if (!$debug) {
            return $this->include(self::$template, [
                'statusText' => $statusText,
                'statusCode' => $statusCode,
            ]);
        }

        $exceptionMessage = $this->escape($exception->getMessage());

        return $this->include($debugTemplate, [
            'exception' => $exception,
            'exceptionMessage' => $exceptionMessage,
            'statusText' => $statusText,
            'statusCode' => $statusCode,
            'logger' => $this->logger instanceof DebugLoggerInterface ? $this->logger : null,
            'currentContent' => \is_string($this->outputBuffer) ? $this->outputBuffer : ($this->outputBuffer)(),
        ]);
    }

    /**
     * Formats an array as a string.
     */
    private function formatArgs(array $args): string
    {
        $result = [];
        foreach ($args as $key => $item) {
            if ('object' === $item[0]) {
                $formattedValue = sprintf('<em>object</em>(%s)', $this->abbrClass($item[1]));
            } elseif ('array' === $item[0]) {
                $formattedValue = sprintf('<em>array</em>(%s)', \is_array($item[1]) ? $this->formatArgs($item[1]) : $item[1]);
            } elseif ('null' === $item[0]) {
                $formattedValue = '<em>null</em>';
            } elseif ('boolean' === $item[0]) {
                $formattedValue = '<em>'.strtolower(var_export($item[1], true)).'</em>';
            } elseif ('resource' === $item[0]) {
                $formattedValue = '<em>resource</em>';
            } else {
                $formattedValue = str_replace("\n", '', $this->escape(var_export($item[1], true)));
            }

            $result[] = \is_int($key) ? $formattedValue : sprintf("'%s' => %s", $this->escape($key), $formattedValue);
        }

        return implode(', ', $result);
    }

    private function escape(string $string): string
    {
        return htmlspecialchars($string, \ENT_COMPAT | \ENT_SUBSTITUTE, $this->charset);
    }

    private function abbrClass(string $class): string
    {
        $parts = explode('\\', $class);
        $short = array_pop($parts);

        return sprintf('<abbr title="%s">%s</abbr>', $class, $short);
    }

    private function getFileRelative(string $file): ?string
    {
        $file = str_replace('\\', '/', $file);

        if (null !== $this->projectDir && 0 === strpos($file, $this->projectDir)) {
            return ltrim(substr($file, \strlen($this->projectDir)), '/');
        }

        return null;
    }

    /**
     * Returns the link for a given file/line pair.
     *
     * @return string|false
     */
    private function getFileLink(string $file, int $line)
    {
        if ($fmt = $this->fileLinkFormat) {
            return \is_string($fmt) ? strtr($fmt, ['%f' => $file, '%l' => $line]) : $fmt->format($file, $line);
        }

        return false;
    }

    /**
     * Formats a file path.
     *
     * @param string $file An absolute file path
     * @param int    $line The line number
     * @param string $text Use this text for the link rather than the file path
     */
    private function formatFile(string $file, int $line, string $text = null): string
    {
        $file = trim($file);

        if (null === $text) {
            $text = $file;
            if (null !== $rel = $this->getFileRelative($text)) {
                $rel = explode('/', $rel, 2);
                $text = sprintf('<abbr title="%s%2$s">%s</abbr>%s', $this->projectDir, $rel[0], '/'.($rel[1] ?? ''));
            }
        }

        if (0 < $line) {
            $text .= ' at line '.$line;
        }

        if (false !== $link = $this->getFileLink($file, $line)) {
            return sprintf('<a href="%s" title="Click to open this file" class="file_link">%s</a>', $this->escape($link), $text);
        }

        return $text;
    }

    private function include(string $name, array $context = []): string
    {
        extract($context, \EXTR_SKIP);
        ob_start();

        include is_file(\dirname(__DIR__).'/Resources/'.$name) ? \dirname(__DIR__).'/Resources/'.$name : $name;

        return trim(ob_get_clean());
    }

    /**
     * Allows overriding the default non-debug template.
     *
     * @param string $template path to the custom template file to render
     */
    public static function setTemplate(string $template): void
    {
        self::$template = $template;
    }
}
