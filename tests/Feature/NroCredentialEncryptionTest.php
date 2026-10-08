<?php

namespace Tests\Feature;

use App\Models\NroAccount;
use App\Services\NroCredentialCipher;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use InvalidArgumentException;
use Tests\TestCase;

class NroCredentialEncryptionTest extends TestCase
{
    private Encrypter $dedicated;

    protected function setUp(): void
    {
        parent::setUp();
        $key = Encrypter::generateKey('AES-256-CBC');
        $this->dedicated = new Encrypter($key, 'AES-256-CBC');
        config(['nro-shop.credential_key' => 'base64:'.base64_encode($key)]);
        Crypt::swap(new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC'));
    }

    public function test_account_and_receiver_remain_readable_after_app_key_changes(): void
    {
        $account = new NroAccount(['game_password' => 'warehouse-password']);
        $raw = $account->getAttributes()['game_password'];
        $receiver = app(NroCredentialCipher::class)->encryptString('{"username":"receiver","password":"receiver-password"}');
        $this->assertSame('warehouse-password', $this->dedicated->decryptString($raw));
        $this->assertSame('receiver-password', json_decode($this->dedicated->decryptString($receiver), true)['password']);

        Crypt::swap(new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC'));
        $reloaded = (new NroAccount)->setRawAttributes(['game_password' => $raw], true);
        $this->assertSame('warehouse-password', $reloaded->game_password);
        $this->assertSame('receiver-password', json_decode(app(NroCredentialCipher::class)->decryptString($receiver), true)['password']);
        $this->assertArrayNotHasKey('game_password', $reloaded->toArray());
        $this->assertSame($raw, $reloaded->getAttributes()['game_password']);
        $this->assertFalse($reloaded->isDirty());
    }

    public function test_legacy_app_key_and_previous_key_records_are_read_without_rewriting_them(): void
    {
        $old = new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC');
        $legacy = $old->encryptString('legacy-password');
        $receiver = $old->encryptString('{"username":"old-receiver","password":"old-password"}');
        Crypt::swap((new Encrypter(Encrypter::generateKey('AES-256-CBC'), 'AES-256-CBC'))->previousKeys([$old->getKey()]));
        $account = (new NroAccount)->setRawAttributes(['game_password' => $legacy], true);

        $this->assertSame('legacy-password', $account->game_password);
        $this->assertSame($legacy, $account->getAttributes()['game_password']);
        $this->assertSame('old-password', json_decode(app(NroCredentialCipher::class)->decryptString($receiver), true)['password']);
        $this->assertSame('current-app-password', app(NroCredentialCipher::class)->decryptString(Crypt::encryptString('current-app-password')));

        $account->game_password = 'updated-password';
        $this->assertSame('updated-password', $this->dedicated->decryptString($account->getAttributes()['game_password']));
    }

    public function test_unconfigured_installations_keep_legacy_encryption(): void
    {
        config(['nro-shop.credential_key' => null]);
        $account = new NroAccount(['game_password' => 'legacy-password']);
        $this->assertSame('legacy-password', Crypt::decryptString($account->getAttributes()['game_password']));
        $this->assertSame('legacy-password', $account->game_password);
        $this->assertSame('receiver', Crypt::decryptString(app(NroCredentialCipher::class)->encryptString('receiver')));
    }

    public function test_clearing_a_password_preserves_null_and_empty_passwords_remain_encrypted(): void
    {
        $account = new NroAccount(['game_password' => '']);
        $this->assertNotSame('', $account->getAttributes()['game_password']);
        $this->assertSame('', $account->game_password);
        $account->game_password = null;
        $this->assertNull($account->getAttributes()['game_password']);
        $this->assertNull($account->game_password);
    }

    public function test_invalid_configured_key_rejects_writes_without_falling_back(): void
    {
        config(['nro-shop.credential_key' => 'base64:not-a-valid-key']);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('NRO_CREDENTIAL_KEY must be a valid 32-byte AES-256 key.');
        new NroAccount(['game_password' => 'must-not-be-written']);
    }

    public function test_tampered_payload_is_rejected_by_both_dedicated_and_legacy_readers(): void
    {
        $payload = json_decode(base64_decode($this->dedicated->encryptString('secret')), true);
        $payload['mac'] = str_repeat('0', 64);
        $this->expectException(DecryptException::class);
        app(NroCredentialCipher::class)->decryptString(base64_encode(json_encode($payload)));
    }

    public function test_dedicated_cipher_does_not_replace_laravels_global_cipher(): void
    {
        $ciphertext = app(NroCredentialCipher::class)->encryptString('nro-only');
        $this->assertSame('regular-data', Crypt::decryptString(Crypt::encryptString('regular-data')));
        $this->expectException(DecryptException::class);
        Crypt::decryptString($ciphertext);
    }
}
