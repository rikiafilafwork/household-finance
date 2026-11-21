// k6: tulis paralel ke SATU wallet, sebagian dengan client_id duplikat (Architecture.md § Minggu 2).
//
// Tujuan: membuktikan saldo tidak meleset dan retry tidak membuat transaksi dobel walau
// 50 klien menulis bersamaan ke wallet yang sama. Setelah k6 selesai, `make load-test`
// menjalankan `wallets:reconcile`, yang harus melaporkan selisih 0.
//
// Jalankan: make load-test   (atau lihat load-tests/README.md)

import http from 'k6/http';
import { check, fail } from 'k6';
import { Counter } from 'k6/metrics';

const BASE_URL = __ENV.BASE_URL || 'http://nginx';
const EMAIL = __ENV.LOADTEST_EMAIL || 'loadtest@example.com';
const PASSWORD = __ENV.LOADTEST_PASSWORD || 'rahasia-panjang';
const VUS = Number(__ENV.VUS || 50);
const DURATION = __ENV.DURATION || '20s';
const DUPLICATE_RATIO = Number(__ENV.DUPLICATE_RATIO || 0.3);
const AMOUNT = 1_000; // nominal tetap supaya saldo akhir mudah diverifikasi
const OPENING = 1_000_000_000;

const created = new Counter('tx_created_201');
const replayed = new Counter('tx_replayed_200');

export const options = {
  scenarios: {
    same_wallet: { executor: 'constant-vus', vus: VUS, duration: DURATION },
  },
  thresholds: {
    // Hanya 201 (baru) atau 200 (retry client_id) yang boleh. Satu 4xx/5xx = gagal.
    checks: ['rate==1'],
    http_req_failed: ['rate==0'],
  },
};

function headers(token) {
  return {
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
    },
  };
}

export function setup() {
  const login = http.post(
    `${BASE_URL}/api/auth/token`,
    JSON.stringify({ email: EMAIL, password: PASSWORD, device_name: 'k6' }),
    headers(),
  );
  if (login.status !== 201) fail(`login gagal: ${login.status} ${login.body}`);
  const token = login.json('token');

  const household = http.post(
    `${BASE_URL}/api/households`,
    JSON.stringify({ name: `Load test ${new Date().toISOString()}` }),
    headers(token),
  ).json('data.id');

  const wallet = http.post(
    `${BASE_URL}/api/households/${household}/wallets`,
    JSON.stringify({ name: 'Wallet rebutan', type: 'bank', opening_balance: OPENING }),
    headers(token),
  ).json('data.id');

  // Kumpulan client_id yang sengaja dipakai ulang oleh banyak VU secara bersamaan.
  const duplicatePool = Array.from({ length: 200 }, () => crypto.randomUUID());

  return { token, household, wallet, duplicatePool };
}

export default function (data) {
  const clientId =
    Math.random() < DUPLICATE_RATIO
      ? data.duplicatePool[Math.floor(Math.random() * data.duplicatePool.length)]
      : crypto.randomUUID();

  const res = http.post(
    `${BASE_URL}/api/households/${data.household}/transactions`,
    JSON.stringify({
      wallet_id: data.wallet,
      type: 'expense',
      amount: AMOUNT,
      occurred_on: '2026-11-21',
      note: 'k6',
      client_id: clientId,
    }),
    headers(data.token),
  );

  check(res, { 'status 201 atau 200': (r) => r.status === 201 || r.status === 200 });
  if (res.status === 201) created.add(1);
  if (res.status === 200) replayed.add(1);
}

// Verifikasi akhir lewat API: jumlah baris, client_id unik, dan saldo cocok dengan baris.
export function teardown(data) {
  const ids = [];
  let url = `${BASE_URL}/api/households/${data.household}/transactions?per_page=100&wallet_id=${data.wallet}`;
  while (url) {
    const page = http.get(url, headers(data.token));
    for (const tx of page.json('data')) ids.push(tx.client_id);
    url = page.json('links.next');
  }

  const balance = http.get(`${BASE_URL}/api/households/${data.household}/wallets/${data.wallet}`, headers(data.token)).json('data.balance');
  const unique = new Set(ids).size;
  const expectedBalance = OPENING - AMOUNT * ids.length;

  console.log(`baris transaksi: ${ids.length}, client_id unik: ${unique}, saldo: ${balance}, seharusnya: ${expectedBalance}`);

  if (unique !== ids.length) fail(`ADA DUPLIKAT: ${ids.length - unique} baris dengan client_id ganda`);
  if (balance !== expectedBalance) fail(`SALDO MELESET: ${balance} ≠ ${expectedBalance}`);
}
