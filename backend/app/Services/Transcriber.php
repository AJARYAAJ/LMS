<?php

namespace App\Services;

use App\Integrations\IntegrationManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Speech-to-text for uploaded call recordings (Deepgram). The first speaker is
 * taken to be the rep; everyone else is the lead.
 */
class Transcriber
{
    public function __construct(private IntegrationManager $integrations) {}

    public function available(int $organizationId): bool
    {
        return (bool) $this->integrations->provider($organizationId, 'deepgram');
    }

    /** @return list<array{role: string, text: string}> */
    public function transcribe(int $organizationId, UploadedFile $audio): array
    {
        $i = $this->integrations->provider($organizationId, 'deepgram') ?? throw new RuntimeException('Connect a transcription provider under Settings → Integrations to upload recordings.');

        $response = Http::withHeaders(['Authorization' => 'Token '.$i->setting('api_key'), 'Content-Type' => $audio->getMimeType() ?: 'audio/mpeg'])
            ->timeout(120)
            ->withBody(file_get_contents($audio->getRealPath()), $audio->getMimeType() ?: 'audio/mpeg')
            ->post('https://api.deepgram.com/v1/listen?'.http_build_query(['model' => $i->setting('model') ?: 'nova-3', 'diarize' => 'true', 'smart_format' => 'true', 'utterances' => 'true', 'punctuate' => 'true']));

        if ($response->failed()) {
            throw new RuntimeException('Transcription failed ('.$response->status().').');
        }

        $utterances = $response->json('results.utterances') ?? [];
        if (! $utterances) {
            $text = $response->json('results.channels.0.alternatives.0.transcript');

            return $text ? [['role' => 'lead', 'text' => $text]] : [];
        }
        $rep = $utterances[0]['speaker'] ?? 0;

        return array_values(array_map(fn ($u) => ['role' => ($u['speaker'] ?? 0) === $rep ? 'agent' : 'lead', 'text' => trim($u['transcript'] ?? '')], array_filter($utterances, fn ($u) => trim($u['transcript'] ?? '') !== '')));
    }

    /**
     * Pasted notes or a transcript → turns. "Me:", "Rep:", "You:" lines are the rep;
     * anything else (including free-form notes) counts as what the lead said.
     *
     * @return list<array{role: string, text: string}>
     */
    public static function fromText(string $text, ?string $repName = null): array
    {
        $rep = ['me', 'rep', 'you', 'agent', 'sales', 'i'];
        if ($repName) {
            $rep[] = strtolower(strtok($repName, ' '));
        }
        $turns = [];
        foreach (preg_split('/\r?\n/', trim($text)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (preg_match('/^([\p{L} .\'-]{1,30}):\s*(.+)$/u', $line, $m)) {
                $turns[] = ['role' => in_array(strtolower(trim($m[1])), $rep, true) ? 'agent' : 'lead', 'text' => $m[2]];
            } else {
                $turns[] = ['role' => 'lead', 'text' => $line];
            }
        }

        return $turns;
    }
}
