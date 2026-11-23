<?php
// cron/send_reminders.php
// Sends the "7 days before" and "1 day before" next-session reminder emails.
// Run it ONCE A DAY (e.g. 8:00 AM) with a cron job:
//   php /path/to/city-pet-vaccination-api/cron/send_reminders.php
// Running it more than once a day is safe: each reminder is only sent once.

date_default_timezone_set('Asia/Manila');

// Only allow running from the server's cron (not from a browser)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

chdir(dirname(__DIR__));
require_once dirname(__DIR__) . '/services/ReminderService.php';

$started = date('Y-m-d H:i:s');

try {
    $service = new ReminderService();
    $vac = $service->sendVaccinationReminders();
    $dew = $service->sendDewormingReminders();

    $line = sprintf(
        "[%s] Vaccination reminders: sent %d, failed %d | Deworming reminders: sent %d, failed %d",
        $started,
        $vac['sent'] ?? 0, $vac['failed'] ?? 0,
        $dew['sent'] ?? 0, $dew['failed'] ?? 0
    );
    if (empty($vac['success'])) $line .= " | Vaccination error: " . ($vac['error'] ?? 'unknown');
    if (empty($dew['success'])) $line .= " | Deworming error: " . ($dew['error'] ?? 'unknown');
} catch (\Throwable $e) {
    $line = "[$started] Reminder run failed: " . $e->getMessage();
}

echo $line . PHP_EOL;
error_log($line);