# kandita/paydita-laravel

Client Laravel untuk Paydita. **Dokumen dan paket internal PT. Kandita Digital Integrasi. Jangan dipublikasikan.**

Paket ini menandatangani request, membuat order, dan memverifikasi event dari Paydita, sehingga aplikasi Anda tidak menulis HMAC sendiri.

## Pasang

Paket ini privat. Tambahkan repositorinya di `composer.json` aplikasi Anda, lalu:

```
composer require kandita/paydita
```

Isi `.env` (hanya di backend; nilai berasal dari Super Admin Paydita, per environment):

```
PAYDITA_BASE_URL=https://pay.kandita.id
PAYDITA_KEY=pk_sand_...
PAYDITA_SECRET=ps_sand_...
PAYDITA_SIGNING_SECRET=whsec_sand_...
```

Tes koneksi: `php artisan paydita:ping`

## Membuat pembayaran

Produk dan harga milik aplikasi Anda. Paydita menghitung total dari item.

```php
use Kandita\Paydita\Exceptions\PayditaException;
use Kandita\Paydita\Facades\Paydita;

try {
    $order = Paydita::createOrder(
        externalOrderId: $invoice->number,
        items: [['id' => 'school-annual', 'name' => 'Langganan tahunan', 'price' => 2_000_000, 'quantity' => 1]],
        customer: ['name' => $user->name, 'email' => $user->email],
        returnUrl: route('billing.result'),
    );
} catch (PayditaException $e) {
    report($e); // errorCode() dan requestId() aman dicatat; secret tidak pernah masuk pesan
    abort(502, 'Pembayaran belum dapat dibuat.');
}

return $order->redirectToCheckout(); // tidak ada popup: pembeli diarahkan ke halaman bayar
```

Menyimpan `$order->uuid` di data tagihan Anda. Mengulang `createOrder` dengan `externalOrderId` yang sama (misalnya setelah timeout) aman: tidak membuat order kedua.

Metode lain: `createCheckout($orderUuid)` (setelah checkout kedaluwarsa), `getOrder($uuid)`, `getTransaction($uuid)`.

## Menerima event

Paket mendaftarkan `POST /paydita/events` (ubah dengan `PAYDITA_WEBHOOK_PATH`). Daftarkan URL lengkapnya sebagai **callback URL** aplikasi di Paydita. Rute ini tanpa CSRF, memverifikasi signature dan timestamp, membuang event ganda, lalu memicu event Laravel.

```php
use Illuminate\Support\Facades\Event;
use Kandita\Paydita\Events\PaymentPaid;

Event::listen(PaymentPaid::class, function (PaymentPaid $event) {
    $invoice = Invoice::where('number', $event->externalOrderId())->firstOrFail();

    // Cocokkan nominal dengan tagihan Anda sendiri sebelum mengaktifkan layanan.
    if ($invoice->total !== $event->grossAmount() || $invoice->isPaid()) {
        return;
    }

    $invoice->markPaid();
});
```

Event: `PaymentPaid`, `PaymentFailed`, `PaymentCancelled`, `PaymentExpired`, `PaymentRefunded`, `PaymentPartiallyRefunded` (dan `PaymentEvent` untuk tipe baru yang belum dikenal).

Jika listener melempar exception, rute menjawab `500` dan Paydita mengirim ulang event yang sama. Event ganda dibuang berdasarkan id, memakai cache aplikasi (bukan `array`/`null` di produksi). Tetap cek status tagihan sendiri sebelum bertindak.

## Test di aplikasi Anda

```php
$fake = Paydita::fake();

$this->post('/checkout')->assertRedirect();

$fake->assertOrderCreated(fn ($order) => $order['externalOrderId'] === 'INV-1');
```
