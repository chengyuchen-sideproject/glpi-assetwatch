<?php

/**
 * Unit tests cover the GLPI-independent core layer only (src/Core).
 * Integration with GLPI is exercised by dev/scenarios against a real instance.
 */

date_default_timezone_set('Asia/Taipei');
require_once __DIR__ . '/../src/autoload.php';
