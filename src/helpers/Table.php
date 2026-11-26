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

namespace digitalastronaut\craftbouy\helpers;

/**
 * Class Table
 *
 * @author      Digitalastronaut
 * @package     Bouy
 * @since       v1.0.0-beta
 */
abstract class Table extends \craft\db\Table {
    public const BOUY_INCIDENTS = '{{%bouy_incidents}}';
    public const BOUY_INCIDENT_OCCURRENCE_BUCKETS = '{{%bouy_incident_occurrence_buckets}}';
    public const BOUY_RESPONSE_TIME_BUCKETS = '{{%bouy_response_time_buckets}}';
    public const BOUY_DEPENDENCY_REPORTS = '{{%bouy_dependency_reports}}';
}
