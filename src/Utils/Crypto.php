<?php

declare(strict_types=1);

namespace App\Utils;

use SodiumException;

class Crypto
{
    private string $encryptionKey;
    private string $indexKey;
    
    // Define constants if sodium extension is not loaded (shouldn't happen, but safety check)
    private const KEYBYTES = 32; // SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
    private const NPUBBYTES = 24; // SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES

    public function __construct(string $encryptionKey, string $indexKey)
    {
        // Check if sodium extension is loaded
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('Sodium extension is not loaded. Please install ext-sodium.');
        }
        
        // Get the constant value (use constant() to handle namespace issues)
        $keyBytes = defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES') 
            ? constant('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES') 
            : self::KEYBYTES;
        
        // Try to decode base64, if it fails, use as-is
        $decodedKey = base64_decode($encryptionKey, true);
        if ($decodedKey !== false && strlen($decodedKey) === $keyBytes) {
            $this->encryptionKey = $decodedKey;
        } elseif (strlen($encryptionKey) === $keyBytes) {
            $this->encryptionKey = $encryptionKey;
        } else {
            throw new \InvalidArgumentException('Encryption key must be ' . $keyBytes . ' bytes (or base64 encoded)');
        }

        // Try to decode base64 for index key
        $decodedIndexKey = base64_decode($indexKey, true);
        if ($decodedIndexKey !== false && strlen($decodedIndexKey) >= 16) {
            $this->indexKey = $decodedIndexKey;
        } elseif (strlen($indexKey) >= 16) {
            $this->indexKey = $indexKey;
        } else {
            throw new \InvalidArgumentException('Index key must be at least 16 bytes (or base64 encoded)');
        }
    }

    /**
     * Encrypt data using XChaCha20-Poly1305
     */
    public function encrypt(string $plaintext): string
    {
        $nonceBytes = defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES') 
            ? constant('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES') 
            : self::NPUBBYTES;
        $nonce = random_bytes($nonceBytes);
        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            '',
            $nonce,
            $this->encryptionKey
        );

        // Prepend nonce to ciphertext and base64 encode
        return base64_encode($nonce . $ciphertext);
    }

    /**
     * Decrypt data using XChaCha20-Poly1305
     */
    public function decrypt(string $ciphertext): string
    {
        $data = base64_decode($ciphertext, true);
        if ($data === false) {
            throw new \RuntimeException('Invalid base64 ciphertext');
        }

        $nonceBytes = defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES') 
            ? constant('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES') 
            : self::NPUBBYTES;
        $nonceLength = $nonceBytes;
        if (strlen($data) < $nonceLength) {
            throw new \RuntimeException('Ciphertext too short');
        }

        $nonce = substr($data, 0, $nonceLength);
        $ciphertext = substr($data, $nonceLength);

        $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            '',
            $nonce,
            $this->encryptionKey
        );

        if ($plaintext === false) {
            throw new \RuntimeException('Decryption failed');
        }

        return $plaintext;
    }

    /**
     * Generate blind-index token hash
     */
    public function token(string $token): string
    {
        $hash = sodium_crypto_generichash($token, $this->indexKey, 16);
        return $this->base64urlEncode($hash);
    }

    /**
     * Base64url encode (URL-safe)
     */
    private function base64urlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

