<?php

namespace Amzad\PaymentGateways\Support;

use Amzad\PaymentGateways\Exceptions\InvalidGatewayConfigurationException;
use Amzad\PaymentGateways\Exceptions\PaymentGatewayException;

/**
 * AES-CBC encryption with hex-encoded output, as used by Gulf gateways that encrypt
 * their payloads (Hesabe: AES-256-CBC with a secret key and IV key; KNET: AES-128-CBC).
 *
 * Encryption uses standard PKCS#7 padding. Decryption strips the padding by hand and
 * accepts pad lengths up to 32, because some provider kits pad to 32-byte blocks.
 */
final class AesCrypt
{
    /**
     * @throws InvalidGatewayConfigurationException When the key or IV has the wrong length for the cipher.
     */
    public function __construct(
        private readonly string $key,
        private readonly string $iv,
        private readonly string $cipher = 'AES-256-CBC',
    ) {
        if (strlen($key) !== openssl_cipher_key_length($cipher)) {
            throw new InvalidGatewayConfigurationException("Encryption key must be ".openssl_cipher_key_length($cipher)." characters long for {$cipher}.");
        }

        if (strlen($iv) !== openssl_cipher_iv_length($cipher)) {
            throw new InvalidGatewayConfigurationException("Encryption IV must be ".openssl_cipher_iv_length($cipher)." characters long for {$cipher}.");
        }
    }

    public function encrypt(string $plainText): string
    {
        return bin2hex((string) openssl_encrypt($plainText, $this->cipher, $this->key, OPENSSL_RAW_DATA, $this->iv));
    }

    /**
     * @throws PaymentGatewayException When the input is not valid ciphertext for this key.
     */
    public function decrypt(string $hex): string
    {
        $hex = trim($hex);

        if (empty($hex) || strlen($hex) % 32 !== 0 || ! ctype_xdigit($hex)) {
            throw new PaymentGatewayException('Payload is not a valid encrypted string.');
        }

        $padded = (string) openssl_decrypt((string) hex2bin($hex), $this->cipher, $this->key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $this->iv);
        $pad = empty($padded) ? 0 : ord($padded[strlen($padded) - 1]);

        if ($pad < 1 || $pad > 32 || $pad > strlen($padded) || substr($padded, -$pad) !== str_repeat(chr($pad), $pad)) {
            throw new PaymentGatewayException('Payload could not be decrypted. Check the encryption key and IV.');
        }

        return substr($padded, 0, -$pad);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function encryptJson(array $data): string
    {
        return $this->encrypt((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string, mixed>
     *
     * @throws PaymentGatewayException When the payload cannot be decrypted or is not a JSON object.
     */
    public function decryptJson(string $hex): array
    {
        // Payment tokens are long digit strings; keep any sent as bare numbers exact.
        $data = json_decode($this->decrypt($hex), true, 512, JSON_BIGINT_AS_STRING);

        if (! is_array($data)) {
            throw new PaymentGatewayException('Decrypted payload is not valid JSON.');
        }

        return $data;
    }
}
