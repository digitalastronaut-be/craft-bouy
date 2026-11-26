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

namespace digitalastronaut\craftbouy\jobs;

use Craft;
use craft\helpers\App;
use craft\queue\BaseJob;

use digitalastronaut\craftbouy\Bouy;

use Throwable;

/**
 * Class NotifyNewIncidentJob
 *
 * Pings Harbour that *a* new incident exists on this site — deliberately
 * carries no incident detail of its own (no message/url/count). Pushed
 * by `AlertService::reportError()` only the first time a given incident
 * fingerprint is ever seen; Harbour reacts by pulling the current,
 * complete incident list from `actions/bouy/incidents` rather than being
 * sent one. A failed ping is only ever logged — it must never throw
 * back into anything.
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class NotifyNewIncidentJob extends BaseJob {
    public string $monitorUid = '';

    /**
     * @inheritdoc
     */
    public function execute($queue): void {
        $settings = Bouy::getInstance()->getSettings();
        $harbourUrl = App::parseEnv($settings->harbourUrl);

        if ($harbourUrl === '') return;

        try {
            Craft::createGuzzleClient()->post(rtrim($harbourUrl, '/') . '/actions/harbour/incidents/notify', [
                'json' => [
                    'monitorUid' => $this->monitorUid,
                ],
                'headers' => [
                    'Authorization' => 'Bearer ' . App::parseEnv($settings->harbourApiKey),
                ],
                'timeout' => 5,
            ]);
        } catch (Throwable $e) {
            Craft::warning("Couldn't notify Harbour of a new incident: {$e->getMessage()}", __METHOD__);
        }
    }

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string {
        return Craft::t('bouy', 'Notifying Harbour of a new incident');
    }
}
