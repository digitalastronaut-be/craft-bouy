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

namespace digitalastronaut\craftbouy\controllers;

use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use craft\web\Response;

use digitalastronaut\craftbouy\Bouy;
use digitalastronaut\craftbouy\services\ResponseTimeService;

use DateTime;
use DateTimeZone;
use yii\web\UnauthorizedHttpException;

/**
 * Class ResponseTimesController
 *
 * Reachable at `actions/bouy/response-times` by Harbour only — not a CP
 * page, so it's gated by the shared API key rather than a Craft user
 * permission, same as `StatusController`/`IncidentsController`.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class ResponseTimesController extends Controller {
    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = ['index'];

    /**
     * Accepts optional `start`/`end` (ISO 8601, assumed UTC if no offset
     * is given) query params — defaulting to the last `ResponseTimeService::
     * REPORT_HOURS` when either is omitted preserves this endpoint's
     * original "last 24h" behavior exactly, so existing callers that
     * never pass a range see no change beyond the added
     * `resolutionMinutes` key.
     *
     * @return Response
     * @throws UnauthorizedHttpException
     */
    public function actionIndex(): Response {
        if (!Bouy::getInstance()->getApiKey()->isValid($this->_bearerToken())) {
            throw new UnauthorizedHttpException('Invalid or missing API key.');
        }

        $end = $this->_dateParam('end') ?? DateTimeHelper::currentUTCDateTime();
        $start = $this->_dateParam('start') ?? (clone $end)->modify('-' . ResponseTimeService::REPORT_HOURS . ' hours');

        $report = Bouy::getInstance()->getResponseTime()->getReportForRange($start, $end);

        return $this->asJson([
            'buckets' => $report['points'],
            'resolutionMinutes' => $report['resolutionMinutes'],
            'binEdgesMs' => ResponseTimeService::BIN_EDGES_MS,
        ]);
    }

    /**
     * @param string $name
     * @return DateTime|null
     */
    private function _dateParam(string $name): ?DateTime {
        $value = $this->request->getQueryParam($name);

        return $value !== null ? new DateTime($value, new DateTimeZone('UTC')) : null;
    }

    /**
     * @return string|null
     */
    private function _bearerToken(): ?string {
        $header = $this->request->getHeaders()->get('Authorization');

        if ($header === null || !str_starts_with($header, 'Bearer ')) return null;

        return substr($header, 7);
    }
}
