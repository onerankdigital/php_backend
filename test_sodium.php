<?php
/**
 * Test Sodium Extension
 * Access at: http://localhost/enquiry-form/backend/test_sodium.php
 */

header('Content-Type: text/plain');

echo "Sodium Extension Test\n";
echo "====================\n\n";

if (extension_loaded('sodium')) {
    echo "✓ Sodium extension is loaded\n\n";
    
    // Test if constants are defined
    if (defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES')) {
        echo "✓ SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES = " . SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES . "\n";
    } else {
        echo "✗ SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES is NOT defined\n";
    }
    
    if (defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES')) {
        echo "✓ SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES = " . SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES . "\n";
    } else {
        echo "✗ SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES is NOT defined\n";
    }
    
    // Test function availability
    if (function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_keygen')) {
        echo "✓ sodium_crypto_aead_xchacha20poly1305_ietf_keygen() is available\n";
    } else {
        echo "✗ sodium_crypto_aead_xchacha20poly1305_ietf_keygen() is NOT available\n";
    }
    
} else {
    echo "✗ Sodium extension is NOT loaded\n\n";
    echo "To enable sodium extension in XAMPP:\n";
    echo "1. Open php.ini (usually in C:\\xampp\\php\\php.ini)\n";
    echo "2. Find the line: ;extension=sodium\n";
    echo "3. Remove the semicolon to uncomment: extension=sodium\n";
    echo "4. Restart Apache\n";
}

echo "\nPHP Version: " . PHP_VERSION . "\n";
echo "Loaded Extensions: " . implode(', ', get_loaded_extensions()) . "\n";

