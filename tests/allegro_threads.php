<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

// Centrum wiadomości Allegro beta.v1: kto jest kupującym, kto nami, Problem z zakupem (bez sieci).
require dirname(__DIR__) . '/src/Services/AllegroThreads.php';
use Pase\Services\AllegroThreads as T;

$checks = 0;
function check(bool $ok, string $label): void { global $checks; if (!$ok) { throw new RuntimeException('FAIL: ' . $label); } ++$checks; echo "OK: $label\n"; }

$problem = ['id' => 't1', 'type' => 'POST_PURCHASE_ISSUE', 'status' => 'CLOSED', 'subType' => 'PRODUCT_INCONSISTENT_WITH_THE_OFFER',
    'participants' => [['role' => 'SELLER', 'login' => 'sklep'], ['role' => 'BUYER', 'login' => 'ola']]];
check(T::isProblem($problem) && T::isClosed($problem) && !T::isProblem(['type' => 'COMMON']) && !T::isClosed(['status' => 'OPEN']), 'thread type and status');
check(T::subTypeLabel('PRODUCT_INCONSISTENT_WITH_THE_OFFER') === 'Produkt niezgodny z ofertą' && T::subTypeLabel('NEW_ONE') === '' && T::subTypeLabel(['x']) === '', 'subtype labels');
check(T::buyerLogin($problem) === 'ola', 'buyer from participant role BUYER');
$common = ['participants' => [['role' => 'USER', 'login' => 'sklep'], ['role' => 'USER', 'login' => 'jan']]];
check(T::buyerLogin($common, 'SKLEP') === 'jan', 'USER roles: the other login than our account');
check(T::buyerLogin(['interlocutor' => ['login' => 'old']]) === 'old' && T::buyerLogin([]) === '', 'public.v1 interlocutor still read');
check(T::isMine(['role' => 'SELLER']) && !T::isMine(['role' => 'BUYER', 'login' => 'sklep'], 'sklep') && !T::isMine(['role' => 'ADMIN']), 'author by role');
check(T::isMine(['role' => 'USER', 'login' => 'Sklep'], 'sklep') && !T::isMine(['role' => 'USER', 'login' => 'jan'], 'sklep') && !T::isMine(['role' => 'USER', 'login' => 'jan']), 'USER author by our login');
check(T::isMine(['isInterlocutor' => false]) && !T::isMine(['isInterlocutor' => true, 'role' => 'SELLER']), 'public.v1 isInterlocutor still read');
check(T::authorRole(['role' => 'ADMIN']) === 'ADMIN' && T::authorRole(['role' => 'USER', 'login' => 'sklep'], 'sklep') === 'SELLER' && T::authorRole(['role' => 'USER', 'login' => 'x'], 'sklep') === 'BUYER', 'role saved in CRM');

echo "\n$checks checks passed\n";
