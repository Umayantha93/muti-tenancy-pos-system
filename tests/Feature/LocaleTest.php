<?php

namespace Tests\Feature;

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    public function test_translations_endpoint_returns_english_ui_messages(): void
    {
        $this->getJson('/api/translations?locale=en')
            ->assertOk()
            ->assertJsonPath('locale', 'en')
            ->assertJsonPath('messages.shell.sign_out', 'Sign out')
            ->assertJsonPath('messages.nav.Overview', 'Overview');
    }

    public function test_translations_endpoint_returns_sinhala_ui_messages(): void
    {
        $this->getJson('/api/translations?locale=si')
            ->assertOk()
            ->assertJsonPath('locale', 'si')
            ->assertJsonPath('messages.shell.sign_out', 'ඉවත් වන්න')
            ->assertJsonPath('messages.nav.Overview', 'දළ විශ්ලේෂණය');
    }

    public function test_authenticated_user_can_persist_locale(): void
    {
        $user = User::factory()->create([
            'tenant_id' => null,
            'role' => 'super_admin',
            'status' => 'active',
            'locale' => 'en',
        ]);
        Sanctum::actingAs($user);

        $this->putJson('/api/locale', ['locale' => 'si'])
            ->assertOk()
            ->assertJsonPath('locale', 'si')
            ->assertJsonPath('messages.shell.sign_out', 'ඉවත් වන්න');

        $this->assertSame('si', $user->fresh()->locale);
    }
}
