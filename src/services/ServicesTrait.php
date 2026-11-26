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

/**
 * Class ServicesTrait
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
trait ServicesTrait {
    /**
     * @return array
     */
    public static function config(): array {
        return [
            'components' => [
                'apiKey' => ApiKeyService::class,
                'status' => StatusService::class,
                'alert' => AlertService::class,
                'incidents' => IncidentsService::class,
                'packages' => PackagesService::class,
                'responseTime' => ResponseTimeService::class,
                'sourcePreview' => SourcePreviewService::class,
            ],
        ];
    }

    /**
     * @return ApiKeyService
     */
    public function getApiKey(): ApiKeyService {
        return $this->get('apiKey');
    }

    /**
     * @return StatusService
     */
    public function getStatus(): StatusService {
        return $this->get('status');
    }

    /**
     * @return AlertService
     */
    public function getAlert(): AlertService {
        return $this->get('alert');
    }

    /**
     * @return IncidentsService
     */
    public function getIncidents(): IncidentsService {
        return $this->get('incidents');
    }

    /**
     * @return PackagesService
     */
    public function getPackages(): PackagesService {
        return $this->get('packages');
    }

    /**
     * @return ResponseTimeService
     */
    public function getResponseTime(): ResponseTimeService {
        return $this->get('responseTime');
    }

    /**
     * @return SourcePreviewService
     */
    public function getSourcePreview(): SourcePreviewService {
        return $this->get('sourcePreview');
    }
}
