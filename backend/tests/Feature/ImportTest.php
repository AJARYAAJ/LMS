<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_import_maps_headers_and_skips_duplicates(): void
    {
        $admin = $this->organization();
        $this->as($admin)->postJson('/api/v1/leads', ['first_name' => 'Existing', 'email' => 'old@corp.io']);

        $csv = implode("\n", [
            'Full Name,Email Address,Company,Lead Source,Tags,Priority',
            'Jane Cooper,jane@acme.com,Acme,referral,VIP|New Tag,high',
            'Old Timer,old@corp.io,Corp,website,,',
            ',missing@name.com,,,,',
        ]);

        $file = UploadedFile::fake()->createWithContent('leads.csv', $csv);

        $this->as($admin)->post('/api/v1/leads/import', ['file' => $file], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.created', 1)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.failed', 1);

        $this->as($admin)->getJson('/api/v1/leads?search=jane')
            ->assertJsonPath('data.0.last_name', 'Cooper')
            ->assertJsonPath('data.0.source.name', 'Referral')
            ->assertJsonPath('data.0.priority', 'high')
            ->assertJsonCount(2, 'data.0.tags');
    }
}
