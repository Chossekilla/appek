<?php
/**
 * 💱 Denní kurzy ČNB — vývojářský vstup. Skutečný cron je api/cron_kurzy.php
 * (scripts/ se do instalačního balíku NEbalí, viz build-zip.sh -x "scripts/*").
 *   php scripts/cron_kurzy.php [YYYY-MM-DD]
 * hPanel: 45 14 * * *   domains/<doména>/public_html/api/cron_kurzy.php
 */
require __DIR__ . '/../api/cron_kurzy.php';
