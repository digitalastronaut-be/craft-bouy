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

use Craft;
use craft\base\PluginInterface;
use craft\web\Controller;
use craft\web\Response;

use digitalastronaut\craftbouy\Bouy;

/**
 * Class SettingsController
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class SettingsController extends Controller {
    public $defaultAction = 'index';
    protected array|int|bool $allowAnonymous = self::ALLOW_ANONYMOUS_NEVER;

    /**
     * @param PluginInterface|null $plugin
     * @return Response
     */
    public function actionIndex(?PluginInterface $plugin = null): Response {
        $this->requireAdmin(false);

        $plugin ??= Bouy::getInstance();

        return $this->renderTemplate('bouy/pages/_settings.twig', [
            'plugin' => $plugin,
            'settings' => $plugin->getSettings(),
        ]);
    }

    /**
     * @return Response|null
     */
    public function actionSave(): ?Response {
        $this->requireAdmin(false);
        $this->requirePostRequest();

        $plugin = Bouy::getInstance();

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $this->request->getBodyParam('settings', []))) {
            $this->setFailFlash(Craft::t('bouy', 'Couldn’t save settings.'));

            Craft::$app->getUrlManager()->setRouteParams(['plugin' => $plugin]);

            return null;
        }

        $this->setSuccessFlash(Craft::t('bouy', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
