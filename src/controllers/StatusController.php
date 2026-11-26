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

use yii\web\UnauthorizedHttpException;

/**
 * Class StatusController
 *
 * Reachable at `actions/bouy/status` by Harbour only — not a CP page, so
 * it's gated by the shared API key rather than a Craft user permission.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class StatusController extends Controller {
    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = ['index'];

    /**
     * @return Response
     * @throws UnauthorizedHttpException
     */
    public function actionIndex(): Response {
        if (!Bouy::getInstance()->getApiKey()->isValid($this->_bearerToken())) {
            throw new UnauthorizedHttpException('Invalid or missing API key.');
        }

        return $this->asJson(Bouy::getInstance()->getStatus()->getReport());
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
