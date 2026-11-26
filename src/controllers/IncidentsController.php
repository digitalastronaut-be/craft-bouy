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

use craft\web\Controller;
use craft\web\Response;

use digitalastronaut\craftbouy\Bouy;

use yii\web\NotFoundHttpException;
use yii\web\UnauthorizedHttpException;

/**
 * Class IncidentsController
 *
 * Reachable at `actions/bouy/incidents` by Harbour only — not a CP page,
 * so it's gated by the shared API key rather than a Craft user
 * permission, same as `StatusController`. `actionResolve()` is the one
 * exception to this plugin's otherwise read-only relationship with
 * Harbour — see `PollingService` on the Harbour side for the rest, which
 * only ever reads.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class IncidentsController extends Controller {
    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = ['index', 'resolve'];

    /**
     * @return Response
     * @throws UnauthorizedHttpException
     */
    public function actionIndex(): Response {
        if (!Bouy::getInstance()->getApiKey()->isValid($this->_bearerToken())) {
            throw new UnauthorizedHttpException('Invalid or missing API key.');
        }

        return $this->asJson(['incidents' => Bouy::getInstance()->getIncidents()->getReport()]);
    }

    /**
     * Marks an incident resolved or unresolved — called by Harbour's
     * "Resolve"/"Unresolve" action (`MonitorsController::actionResolveIncident()`).
     *
     * @return Response
     * @throws UnauthorizedHttpException
     * @throws NotFoundHttpException
     */
    public function actionResolve(): Response {
        if (!Bouy::getInstance()->getApiKey()->isValid($this->_bearerToken())) {
            throw new UnauthorizedHttpException('Invalid or missing API key.');
        }

        $this->requirePostRequest();

        $fingerprint = (string) $this->request->getRequiredBodyParam('fingerprint');
        $resolved = (bool) $this->request->getBodyParam('resolved', true);

        if (!Bouy::getInstance()->getIncidents()->setResolved($fingerprint, $resolved)) {
            throw new NotFoundHttpException('Incident not found.');
        }

        return $this->asJson(['success' => true]);
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
