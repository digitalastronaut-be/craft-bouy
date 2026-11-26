<?php
/**
 * Bouy plugin for Craft CMS
 *
 * Small monitoring client exposing a credentialed endpoint that reports
 * version numbers, error logs, and response times to a central Harbour
 * installation.
 *
 * @link      https://digitalastronaut.be
 * @copyright Copyright (c) 2026 Digitalastronaut
 */

namespace digitalastronaut\craftbouy\services;

use craft\base\Component;

use Throwable;
use Twig\Error\Error as TwigError;

/**
 * Class SourcePreviewService
 *
 * Builds a small, line-numbered window of source code around where an
 * exception was thrown — the same "code frame" Sentry/Whoops/Ignition
 * show, letting a developer see the actual bug on Harbour's incident
 * detail page instead of only a stack trace.
 *
 * Twig errors need special handling: `Twig\Template::handleException()`
 * wraps *every* throwable that escapes template rendering into a
 * `Twig\Error\Error` and calls `guess()`, which resolves the real
 * template line via Twig's own compiled debug-info map and attaches a
 * `Twig\Source` carrying the literal `.twig` source text
 * (`getSourceContext()->getCode()`) — regardless of whether a real
 * filesystem path is known. Using that directly means the preview shows
 * the developer's actual template, not the generated PHP Craft compiles
 * it to under `storage/runtime/compiled_templates/` (which is what
 * `$exception->getFile()` would otherwise point at for some Twig error
 * subtypes).
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class SourcePreviewService extends Component {
    // 100 lines either side of the error line, so the preview's roughly
    // 200 lines total — enough to actually see the surrounding function/
    // block, not just the handful of lines immediately around the fault.
    private const CONTEXT_LINES = 100;
    private const MAX_LINE_LENGTH = 300;

    /**
     * @param Throwable $exception
     * @return array{label: string, lines: array<int, array{line: int, code: string, isErrorLine: bool}>}|null
     * `null` when no source could be resolved (e.g. the file no longer
     * exists, or a Twig error Twig itself couldn't map to a line).
     */
    public function buildPreview(Throwable $exception): ?array {
        if ($exception instanceof TwigError) {
            return $this->fromTwigError($exception);
        }

        return $this->fromFile($exception->getFile(), $exception->getLine());
    }

    /**
     * @param TwigError $exception
     * @return array{label: string, lines: array}|null
     */
    private function fromTwigError(TwigError $exception): ?array {
        $source = $exception->getSourceContext();
        $line = $exception->getTemplateLine();

        if ($source === null || $line <= 0) return null;

        $label = $source->getPath() !== '' ? $source->getPath() : $source->getName();

        return $this->buildFromCode($source->getCode(), $line, $label);
    }

    /**
     * @param string $file
     * @param int $line
     * @return array{label: string, lines: array}|null
     */
    private function fromFile(string $file, int $line): ?array {
        if ($file === '' || !is_readable($file)) return null;

        $code = file_get_contents($file);

        if ($code === false) return null;

        return $this->buildFromCode($code, $line, $file);
    }

    /**
     * @param string $code
     * @param int $errorLine 1-based
     * @param string $label
     * @return array{label: string, lines: array}|null
     */
    private function buildFromCode(string $code, int $errorLine, string $label): ?array {
        $allLines = preg_split('/\r\n|\r|\n/', $code);

        if ($allLines === false || !isset($allLines[$errorLine - 1])) return null;

        $totalLines = count($allLines);
        $start = max(1, $errorLine - self::CONTEXT_LINES);
        $end = min($totalLines, $errorLine + self::CONTEXT_LINES);

        $lines = [];

        for ($number = $start; $number <= $end; $number++) {
            $lines[] = [
                'line' => $number,
                'code' => mb_substr($allLines[$number - 1], 0, self::MAX_LINE_LENGTH),
                'isErrorLine' => $number === $errorLine,
            ];
        }

        return ['label' => $label, 'lines' => $lines];
    }
}
