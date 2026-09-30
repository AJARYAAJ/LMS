<?php

namespace App\Reports;

use App\Models\SavedReport;
use App\Models\User;
use App\Services\OrgMailer;

/**
 * Emails a saved report as a plain-text summary (headline number, top rows and a
 * link back to the chart) through the organization's email vendor.
 */
class ReportMailer
{
    public function __construct(private ReportEngine $engine, private OrgMailer $mailer) {}

    /** @return int number of recipients */
    public function send(SavedReport $report, ?User $runAs = null): int
    {
        $owner = $runAs ?? $report->user;
        $result = $this->engine->run($owner, $report->spec);
        $currency = $owner->organization?->currency ?? 'USD';
        $recipients = $report->recipients ?: [$owner->email];
        $app = rtrim((string) config('app.frontend_url'), '/');

        $lines = [
            $report->name,
            "{$result['entity_label']} · {$result['metric_label']}".($result['dimension_label'] ? " by {$result['dimension_label']}" : '')." · {$result['range']['label']}",
            '',
            'TOTAL: '.self::format($result['total'], $result['format'], $currency),
            '',
        ];
        foreach (array_slice($result['rows'], 0, 15) as $row) {
            if ($result['dimension_label']) {
                $lines[] = '• '.str_pad($row['label'], 28).self::format($row['value'], $result['format'], $currency);
            }
        }
        if ($report->description) {
            $lines = [...$lines, '', $report->description];
        }
        $lines = [...$lines, '', "Open the chart: {$app}/reports?tab=studio&report={$report->id}"];

        foreach ($recipients as $email) {
            $this->mailer->send($report->organization_id, $email, null, "Report: {$report->name}", implode("\n", $lines));
        }

        return count($recipients);
    }

    public static function format(float $value, string $format, string $currency = 'USD'): string
    {
        return match ($format) {
            'money' => $currency.' '.number_format($value, 0),
            'percent' => number_format($value, 1).'%',
            'duration' => sprintf('%d:%02d', intdiv((int) $value, 60), (int) $value % 60),
            'decimal' => number_format($value, 1),
            default => number_format($value),
        };
    }
}
