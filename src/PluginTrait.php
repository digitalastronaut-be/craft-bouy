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

namespace digitalastronaut\craftbouy;

use Craft;
use craft\events\ExceptionEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\web\Application;
use craft\web\ErrorHandler;
use craft\web\UrlManager;

use yii\base\Event;

/**
 * Class PluginTrait
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
trait PluginTrait {
    /**
     * @return void
     */
    protected function registerEvents(): void {
        $this->registerErrorHandling();
        $this->registerResponseTimeTracking();

        if (Craft::$app->request->isCpRequest) $this->registerCpEvents();
    }

    /**
     * Records this request's wall-clock duration once it's finished —
     * gated to real front-end site requests (CP/console traffic isn't
     * "the site's response time" in the sense this is meant to track),
     * and excluding Bouy's own `actions/bouy/*` endpoints so Harbour's
     * polling traffic can't skew real-visitor timing.
     *
     * @return void
     */
    protected function registerResponseTimeTracking(): void {
        $request = Craft::$app->getRequest();

        if (!$request->getIsSiteRequest()) return;
        if (str_starts_with($request->getFullPath(), 'actions/bouy/')) return;

        Event::on(
            Application::class,
            Application::EVENT_AFTER_REQUEST,
            function() {
                $durationMs = (int) round((microtime(true) - YII_BEGIN_TIME) * 1000);

                Bouy::getInstance()->getResponseTime()->recordRequest($durationMs);
            }
        );
    }

    /**
     * Pings Harbour whenever this site hits an unhandled server error.
     * Registered unconditionally (not gated to site requests) — a CP
     * request can hit a 500 just as easily as a front-end one, and
     * `craft\web\ErrorHandler` covers both (console errors go through a
     * separate handler and aren't covered here).
     *
     * @return void
     */
    protected function registerErrorHandling(): void {
        Event::on(
            ErrorHandler::class,
            ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION,
            function(ExceptionEvent $event) {
                Bouy::getInstance()->getAlert()->reportError($event->exception);
            }
        );
    }

    /**
     * @return void
     */
    protected function registerCpEvents(): void {
        $this->registerCpRoutes();
    }

    /**
     * @return void
     */
    protected function registerCpRoutes(): void {
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function(RegisterUrlRulesEvent $event) {
                $event->rules['bouy/settings'] = 'bouy/settings/index';
            }
        );
    }
}
