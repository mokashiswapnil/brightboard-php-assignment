<?php

namespace Tests\Feature;

use App\Models\File;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FileControllerTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test BRD-301 Fix: Single quotes in search query do not break SQL or trigger errors.
     */
    public function test_search_handles_single_quotes_without_sql_injection(): void
    {
        $user = User::factory()->create(['account_id' => 1]);

        File::create([
            'account_id'  => 1,
            'owner_id'    => $user->id,
            'external_id' => 'doc_1',
            'name'        => "O'Reilly_PHP_Guide.pdf",
        ]);

        $response = $this->actingAs($user)
            ->getJson("/api/files?search=" . urlencode("O'Reilly"));

        $response->assertOk()
            ->assertJsonPath('data.0.name', "O'Reilly_PHP_Guide.pdf");
    }

    /**
     * Test Multi-Tenant Isolation: Users can never view files belonging to another account.
     */
    public function test_multi_tenant_isolation_prevents_viewing_other_account_files(): void
    {
        $userA = User::factory()->create(['account_id' => 1]);
        $userB = User::factory()->create(['account_id' => 2]);

        $fileB = File::create([
            'account_id'  => 2,
            'owner_id'    => $userB->id,
            'external_id' => 'doc_b',
            'name'        => 'Secret_Acct2_Doc.pdf',
        ]);

        // Attempting to show file belonging to account 2 as user from account 1
        $response = $this->actingAs($userA)->getJson("/api/files/{$fileB->id}");
        $response->assertNotFound();
    }
}
