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

namespace digitalastronaut\craftbouy\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;

/**
 * Class Settings
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class Settings extends Model {
    public string $pluginName = 'Bouy';

    /**
     * Shared secret Harbour must send as `Authorization: Bearer <apiKey>` when
     * pulling this site's status report. Supports an environment variable
     * reference (e.g. `$BOUY_API_KEY`) via `App::parseEnv()`.
     */
    public string $apiKey = '';

    /**
     * Base URL of the central Harbour installation to ping on an unhandled
     * server error. Left blank, the ping is simply never sent. Supports an
     * environment variable reference via `App::parseEnv()`.
     */
    public string $harbourUrl = '';

    /**
     * Shared secret sent as `Authorization: Bearer <harbourApiKey>` when
     * pinging Harbour. Supports an environment variable reference via
     * `App::parseEnv()`.
     */
    public string $harbourApiKey = '';

    /**
     * This site's `Monitor` element `uid` on the Harbour installation
     * above — copied from that Monitor's detail page — sent along with
     * every error ping so Harbour knows which monitor it belongs to.
     * Bouy never validates this against anything; it just relays it.
     */
    public string $monitorUid = '';

    /**
     * @return array
     */
    public function behaviors(): array {
        return array_merge(parent::behaviors(), [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['apiKey', 'harbourUrl', 'harbourApiKey', 'monitorUid'],
            ],
        ]);
    }

    /**
     * @return array
     */
    protected function defineRules(): array {
        return array_merge(parent::defineRules(), [
            [['pluginName', 'apiKey', 'harbourUrl', 'harbourApiKey', 'monitorUid'], 'string'],
            [['apiKey'], 'required'],
            [['harbourUrl'], 'url', 'defaultScheme' => 'https'],
        ]);
    }
}
