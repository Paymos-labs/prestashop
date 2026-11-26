# PrestaShop için Paymos

[English](README.md) | [简体中文](README.zh-CN.md) | [Русский](README.ru.md) | [Español](README.es.md) | [Deutsch](README.de.md) | **Türkçe**

PrestaShop için resmî Paymos ödeme modülü. Ödeme adımında stablecoin ile ödeme seçeneği sunar,
müşteriyi Paymos’un barındırılan ödeme sayfasına yönlendirir ve transfer zincir üzerinde
onaylandığında siparişi **Payment accepted** durumuna geçirir — bunu müşterinin tarayıcıdan
dönüşüyle değil, yalnızca imzalı callback ile yapar.

Kurulum sırasında kendine ait üç sipariş durumu oluşturur; böylece kripto ödemeleri yönetim
panelinizde başka anlam taşıyan mevcut durumları ödünç almak zorunda kalmaz. Fatura, sepetin
para biriminde düzenlenir ve kur müşterinin ödeme yaptığı anda sabitlenir.

[![İzleyin: 12 Resmî CMS Kripto Ödeme Eklentisi — Tek Tıkla Bağlantı (Paymos)](https://i.ytimg.com/vi/VMLUGVumYkg/maxresdefault.jpg)](https://www.youtube.com/watch?v=VMLUGVumYkg)

> **Video anlatım (1:12, 6 dilde altyazılı):** [YouTube’da izleyin](https://www.youtube.com/watch?v=VMLUGVumYkg)

## Gereksinimler

- PrestaShop 1.7.8 ile 9.x arası (9.x dahil);
- `curl`, `hash`, `json` ve `openssl` uzantıları etkin PHP 7.4 veya üzeri;
- HTTPS üzerinden yayınlanan bir vitrin — `PS_SSL_ENABLED` açık ve mağaza URL’si TLS ile sunuluyor olmalı;
- Bu mağazanın siparişlerini toplayacak projenin panelde seçili olduğu bir Paymos hesabı.

Paymos PHP SDK paket içine gömülüdür; mağazada Composer çalıştırmanız gerekmez. Modül kendi
İngilizce, Rusça, Almanca, İspanyolca, Türkçe ve Basitleştirilmiş Çince çevirileriyle gelir.

## Kurulum ve bağlantı

1. En güncel paketi [GitHub Releases](https://github.com/paymos-labs/prestashop/releases/latest) üzerinden indirin.
2. **Module Manager** (Modül Yöneticisi) üzerinden yükleyip etkinleştirin.
3. Paymos panelinde hedef projeyi açın; o an seçili proje otomatik olarak kullanılır.
4. **Module Manager → Paymos → Configure** yolunu açın ve **Connect Paymos** düğmesine tıklayın.
5. Paymos ekranında gösterilen mağaza URL’sini ve seçili projeyi onaylayın.

Paket her işyeri için aynıdır ve içinde hiçbir kimlik bilgisi bulunmaz — API anahtarı, secret,
proje ID’si, webhook secret’ı, token veya cihaz kodu içermez. 5. adımdaki onay verilene kadar
modül kurulu olsa da pasif kalır.

Tek bir onay her iki ortamı da kapsar. Her ortam için Paymos tek etkin Payment anahtarınızı
yeniden kullanır (yoksa yeni bir tane oluşturur) ve bu mağazanın callback URL’si için bir
Invoice webhook’u kaydeder — mevcut bir webhook yalnızca URL, kategori ve proje tamamen
eşleştiğinde yeniden kullanılır; aynı adresteki çakışan bir webhook’un üzerine yazılmaz. Cihaz
token’ı kısa ömürlüdür ve işlem sonrası atılır; Merchant API trafiği HMAC ile imzalanır.

Ardından yapılandırma sayfasında tek bir ayar kalır — **Mode** (Sandbox veya Live) — yanında ise
maskelenmiş anahtarı, bağlı projeyi, callback URL’sini ve mutabakat (reconcile) URL’sini
gösteren salt okunur panel yer alır. İki şey bu sayfanın dışındadır: PrestaShop’un tüm ödeme
ağ geçitlerine uyguladığı ödeme tercihleri altındaki para birimi kısıtlamaları ve bağlantının
bağlı olduğu mağaza URL’si.

## Secret saklama

Kimlik bilgileri ve geçici cihaz durumu, `_COOKIE_KEY_` değerinden türetilen anahtarla
AES-256-GCM zarfı olarak PrestaShop Configuration içinde saklanır. Kaydedilen secret’lar yönetim
sayfasına geri yazdırılmaz ve elle girilemez. Bunları değiştirmenin tek yolu yeniden bağlanmaktır;
bir anahtar veya webhook secret’ı rotasyona alındığında gereken tek adım da budur.

## Sipariş durumları

Kurulumda üç yeni durum eklenir; hiçbiri ödenmiş veya kayıtlı (`logable`) olarak işaretlenmez ve
hiçbiri müşteriye e-posta göndermez:

- **Awaiting Paymos payment** — sipariş oluştu, fatura açık, henüz ödeme gelmedi;
- **Paymos payment confirming** — transfer zincirde görüldü ve onay topluyor;
- **Paymos payment — manual review** — ödeme ulaştı ancak tutarlar artık uyuşmuyor veya eski
  fatura zaten ödenmiş, hâlâ ödenebilir ya da okunamaz durumdayken sipariş değiştiği için yeni
  fatura oluşturulmadı.

Nihai sonuçlar PrestaShop’un kendi durumlarına aktarılır; böylece yönetim panelinizin geri kalanı
olağan şekilde çalışır:

| Gelen olay | Sipariş durumu |
|---|---|
| `invoice.confirming` | Paymos payment confirming |
| `invoice.underpaid_waiting`, `invoice.awaiting_payment` | Awaiting Paymos payment |
| `invoice.paid`, `invoice.paid_over` | Payment accepted |
| `invoice.underpaid` | Payment error |
| `invoice.expired`, `invoice.cancelled` | Canceled |

Ödenmiş bir sipariş asla geri alınmaz: geç ulaşan `cancelled`, `expired` veya `confirming` olayları
siparişin özel notlarına yazılır ve yoksayılır. Ödenmiş bir callback’in tutarı artık siparişle
uyuşmuyorsa sipariş Payment accepted yerine **manual review** durumuna alınır ve karşılaştırılan
tüm rakamları gösteren bir not eklenir. Ödeme zincir üzerinde kesinleştiğinde işlem hash’i ve
blok gezgini bağlantısı not olarak kaydedilir.

Modülü kaldırmak bu üç durumu yerinde bırakır. PrestaShop geçmiş siparişlerin referans verdiği
durumların silinmesine izin vermez; eski siparişleri sahipsiz bırakmak daha kötü bir sonuç
doğurur.

## Müşteri deneyimi

Paymos, kendine özgü eylem çağrısıyla bir ödeme seçeneği olarak görünür. Müşteri bunu seçtiğinde
sepet, modülün `validation` denetleyicisine aktarılır; denetleyici siparişi **Awaiting Paymos
payment** durumunda oluşturur ve barındırılan ödeme sayfasına yönlendirir. Ödenen tutar bu aşamada
sepetten alınmaz, daha sonra doğrulanmış callback üzerinden işlenir.

Çift gönderilen veya yenilenen ödeme adımı ikinci bir sipariş ve ikinci bir fatura üretmez — o
sepet için mevcut sipariş bulunur ve müşteri doğrudan sipariş durum sayfasına yönlendirilir.

Fatura hiç oluşturulamazsa sipariş Payment error durumuna geçer ve müşteri boş bir yönlendirme
yerine bunu açıkça ifade eden bir sayfa görür. Aynı denetleyici olağan senaryo için nötr bir
görünüm sunar: ödeme sayfasını erken kapatan müşteri, ödemenin hâlâ onaylandığını ve açık
faturasına dönen **Continue payment** bağlantısını görür. Ödeme akışı siparişin eski faturasını
değiştirmediyse sayfa siparişin inceleme gerektirdiğini bildirir ve müşteriden mağazayla iletişime
geçmesini ister; ödeme bağlantısı gösterilmez. Bu sayfa her koşulda salt okunurdur ve hiçbir
zaman sipariş durumunu değiştirmez.

## Sandbox’ta test etme

1. **Mode** ayarı Sandbox konumundayken vitrin üzerinden bir sipariş verin.
2. Paymos panelinde ilgili faturayı açın ve denemek istediğiniz sonucu manuel olarak tetikleyin. Sandbox’ta hiçbir işlem zamanlayıcıyla kendiliğinden onaylanmaz.
3. Yönetim panelinde siparişin durum geçmişini ve notlarını izleyin.
4. **Mode** ayarını Live konumuna alın. Tek bağlantı her iki ortamı da hazırladığı için ikinci kez kurulum gerekmez.

Sandbox ödemelerinin arkasında gerçek bir blokzincir bulunmadığından işlem hash’i veya gezgin
bağlantısı kaydedilmez.

## Callback’ler ve mutabakat

Callback denetleyicisi ham gövdeyi ve `X-Webhook-Signature` başlığını
(`t={timestamp},v1={hmac_hex}`, HMAC-SHA256, sabit zamanlı karşılaştırma ve rotasyon sırasında
her iki secret’ı da kabul etme desteği) okur ve gerçek bir HTTP durum kodu döndürür. Olay ID’leri
modülün kendi tablosunda saklanır; bu nedenle yeniden teslim edilen olaylar siparişe ikinci kez
uygulanmadan onaylanır. Herhangi bir nihai durum yazılmadan önce fatura Merchant API’den geri
çekilir ve saklanan anlık görüntüyle tekrar doğrulanır.

```text
https://your-store.example/index.php?fc=module&module=paymos&controller=callback
```

Mutabakat (reconciliation), hiç ulaşmayan bir teslimat için güvenlik ağıdır. Son 24 saat içindeki
sonuçlanmamış en fazla 50 faturayı alır ve her birini ters doğrulama, tutar koruması ve geri alma
koruması devrede kalacak şekilde aynı eşleyici üzerinden geçirir. Bunu tetiklemenin iki yolu vardır:

- yapılandırma sayfasında gösterilen token’lı URL üzerinden HTTP ile — zamanlayıcıya bu adresi
  vermelisiniz; çünkü PrestaShop 9 `modules/` altındaki `.php` dosyalarına doğrudan erişimi
  engeller ve betiğe yönlendirilen bir cron 403 hatası alır;
- kabuktan `php modules/paymos/cron/reconcile.php` komutuyla — token gerektirmez.

Her mutabakat çalışması sonuçlanmamış faturaların yanı sıra bekleyen Paymos iadelerini de kontrol
eder.

## İadeler

Modül, Paymos Merchant API (`/v1/refunds`) üzerinden sunucu tarafından hesaplanan iki modda yerel
iade desteği sunar: `full` (tam fatura iadesi) ve `overpayment` (`paid_over` faturadaki fazla
ödemenin iadesi). Fonlar yeni bir giden blokzincir transferi olarak yalnızca Paymos’un ödenmiş
faturada kaydettiği doğrulanmış `first_sender` adresine gönderilir — elle alıcı cüzdan adresi
girilmez ve keyfî kısmi iade tutarları desteklenmez.

PrestaShop yönetim panelinde **Orders** (Siparişler) düzenleme iznine sahip çalışanlar iki yoldan
iade başlatabilir:

- sipariş detay sayfasındaki **Paymos refunds** paneli (`displayAdminOrderMain`, ayrıca
  **Module Manager → Paymos → Configure** ekranından sipariş ID’si ile de erişilebilir) —
  doğrulanmış `first_sender` adresini, işlem geçmişini ve **Refund in full** ile **Refund
  overpayment** eylemlerini gösterir;
- PrestaShop’un yerel tam sipariş iadesi akışı (`paymosNativeRefund`).

İade fişleri (order slips) ve müşteri iade e-postaları, Paymos iadesi `completed` durumuna
ulaşıp callback denetleyicisi veya `reconcile` uç noktası / CLI betiği aracılığıyla Merchant API
üzerinden doğrulanana kadar bekletilir. Sandbox modunda uçtan uca test için nihai iade sonuçları
(`completed`, `failed`, `cancelled`) simüle edilebilir.

## Sorun giderme

**Bağlantı HTTP mağaza URL’sini reddediyor.** `PS_SSL_ENABLED` ayarını etkinleştirin ve mağazanın
gerçekten TLS üzerinden sunulduğundan emin olun; modül sessizce başarısız olmak yerine hatada bu
ayarı açıkça belirtir.

**Sipariş Awaiting Paymos payment durumunda kalıyor.** Yukarıdaki callback URL’sini ağınızın
dışından çağırın — yönlendirmeler izlenmediği için HTTP kimlik doğrulaması ve yönlendirme olmadan
yanıt vermelidir. Ardından PrestaShop günlüğünde `PaymosPrestaShop` kayıtlarını inceleyin. Mağaza
bir süre erişilemez kaldıysa mutabakat URL’sini bir kez çalıştırdığınızda sipariş durumu yakalanır.

**Bir sipariş manual review durumunda.** Bunun iki nedeni vardır ve sipariş notu hangisi olduğunu
açıkça ayırır. Ya fatura oluşturulduktan sonra ödeme ulaşmadan önce sipariş toplamı değişmiştir —
not anlık görüntüyü, güncel toplamı ve olaydaki tutarı listeler. Ya da eski Paymos faturası zaten
ödenmiş, hâlâ ödenebilir veya okunamaz durumdayken sipariş toplamı, mod ya da proje değişmiştir —
bu durumda not `"Paymos payment needs manual review."` ile başlar. İkinci senaryoda yeni fatura
oluşturulmaz ve müşteriden sizinle iletişime geçmesi istenir; önce Paymos panelinden eski faturayı
kontrol edin. Her iki durumu da elle sonuçlandırın.

**Ödeme adımında Paymos görünmüyor.** Sepetin para birimi modülün izin verilen para birimleri
arasında değilse veya etkin ortam tam olarak bağlanmadıysa Paymos kendini gizler. Önce para birimi
kısıtlamalarını, ardından bağlantı panelini kontrol edin.

- [Dokümantasyon](https://paymos.io/tr/docs/cms-prestashop)
- [Kaynak kodu](https://github.com/paymos-labs/prestashop)
- [Değişiklik günlüğü](CHANGELOG.md)
- [Destek](mailto:support@paymos.io)
