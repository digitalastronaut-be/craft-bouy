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

use Craft;
use craft\base\Component;
use craft\helpers\App;

use digitalastronaut\craftbouy\Bouy;
use digitalastronaut\craftbouy\jobs\NotifyNewIncidentJob;

use Throwable;
use yii\web\HttpException;

/**
 * Class AlertService
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class AlertService extends Component {
    /**
     * Records this error as one occurrence of its incident (grouped by
     * where it was thrown — see `IncidentsService::recordOccurrence()`),
     * and only pings Harbour when it's the *first* time this exact
     * incident has ever been seen. A storm of visitors hitting the same
     * broken template now produces exactly one ping, not one per hit —
     * Harbour pulls the full, current incident list itself afterward
     * (see `AlertsController` on the Harbour side) rather than being
     * sent it.
     *
     * Never called for ordinary 4xx `HttpException`s (a 404/403 isn't an
     * incident worth tracking), and a no-op when no Harbour URL is
     * configured.
     *
     * Wrapped in its own try/catch, deliberately never letting anything
     * here escape: this runs on `ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION`
     * (see `PluginTrait::registerErrorHandling()`) — *before* Craft has
     * logged or rendered the real exception it's reporting on. A second,
     * uncaught exception thrown from here (e.g. `recordOccurrence()`
     * hitting a missing/out-of-date `bouy_incidents` table) doesn't stay
     * a logging failure: it replaces the exception the error handler was
     * already in the middle of handling, so the *original* error never
     * gets logged at all and every request on the site starts 500ing
     * with no useful trace — exactly what reporting an error is supposed
     * to prevent, not cause.
     *
     * @param Throwable $exception
     * @return void
     */
    public function reportError(Throwable $exception): void {
        if ($exception instanceof HttpException && $exception->statusCode < 500) return;

        try {
            $statusCode = $exception instanceof HttpException ? $exception->statusCode : 500;
            $request = Craft::$app->getRequest();
            $url = $request->getIsConsoleRequest() ? null : $request->getAbsoluteUrl();
            $requestMethod = $request->getIsConsoleRequest() ? null : $request->getMethod();
            $userAgent = $request->getIsConsoleRequest() ? null : $request->getUserAgent();

            $isNewIncident = Bouy::getInstance()->getIncidents()->recordOccurrence($exception, $statusCode, $url, $requestMethod, $userAgent);

            if (!$isNewIncident) return;

            $harbourUrl = App::parseEnv(Bouy::getInstance()->getSettings()->harbourUrl);

            if ($harbourUrl === '') return;

            Craft::$app->getQueue()->push(new NotifyNewIncidentJob([
                'monitorUid' => App::parseEnv(Bouy::getInstance()->getSettings()->monitorUid),
            ]));
        } catch (Throwable $reportingError) {
            Craft::warning("Couldn't record incident for {$exception->getMessage()}: {$reportingError->getMessage()}", __METHOD__);
        }
    }
}
