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
use craft\base\Model;
use craft\base\Plugin;
use craft\helpers\UrlHelper;

use digitalastronaut\craftbouy\models\Settings;
use digitalastronaut\craftbouy\services\ServicesTrait;

/**
 * Class Bouy
 *
 * @method static Bouy getInstance()
 * @method Settings getSettings()
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class Bouy extends Plugin {
    use ServicesTrait;
    use PluginTrait;

    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;
    public bool $hasCpSection = false;
    public bool $hasReadOnlyCpSettings = true;

    /**
     * @return void
     */
    public function init(): void {
        parent::init();

        $this->registerEvents();
    }

    /**
     * @return mixed
     */
    public function getSettingsResponse(): mixed {
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('bouy/settings'));
    }

    /**
     * @return Model|null
     */
    protected function createSettingsModel(): ?Model {
        return Craft::createObject(Settings::class);
    }
}
