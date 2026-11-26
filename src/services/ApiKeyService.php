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
use craft\helpers\App;

use digitalastronaut\craftbouy\Bouy;

/**
 * Class ApiKeyService
 *
 * Validates the shared secret Harbour sends when pulling this site's status
 * report. A missing or blank configured key always fails closed — it's
 * never treated as "no key required".
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
class ApiKeyService extends Component {
    /**
     * @param string|null $provided
     * @return bool
     */
    public function isValid(?string $provided): bool {
        $configured = App::parseEnv(Bouy::getInstance()->getSettings()->apiKey);

        if ($provided === null || $configured === '') return false;

        return hash_equals($configured, $provided);
    }
}
