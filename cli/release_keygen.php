<?php
declare(strict_types=1);

/**
 * Nowa para kluczy do podpisywania paczek Veless (Ed25519).
 *   php cli/release_keygen.php
 * Uruchom na SWOIM komputerze. Klucz publiczny wpisz do src/Support/ReleaseSignature.php (PUBLIC_KEYS),
 * a prywatny wklej jako sekret VELESS_SIGNING_KEY w GitHubie i zapisz w menedżerze haseł. Nigdzie indziej.
 */
if (PHP_SAPI !== 'cli') { exit('Uruchom z linii poleceń.'); }
if (!function_exists('sodium_crypto_sign_keypair')) { fwrite(STDERR, "Potrzebne rozszerzenie PHP sodium.\n"); exit(1); }
$kp = sodium_crypto_sign_keypair();
fwrite(STDOUT, "Klucz publiczny (do src/Support/ReleaseSignature.php):\n" . base64_encode(sodium_crypto_sign_publickey($kp)) . "\n\n");
fwrite(STDOUT, "Klucz prywatny (TAJNY - tylko sekret VELESS_SIGNING_KEY w GitHubie i menedżer haseł):\n" . base64_encode(sodium_crypto_sign_secretkey($kp)) . "\n");
