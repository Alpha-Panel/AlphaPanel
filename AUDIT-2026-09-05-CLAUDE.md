# AlphaPanel — Panel Yazılımı Güvenliği, Hata, Kod Kalitesi ve Performans İncelemesi

**İnceleme tarihi:** 5–6 Eylül 2026  
**İncelenen depo:** `D:\Projects\AlphaPanel-Docker`  
**Referans commit:** `3e1b53f07c2dad5fcd6d7bdbeb051717ff56b3ab`  
**Odak:** Laravel panelinin web/API kimlik doğrulaması, yetkilendirmesi, dosya/komut/config işlemleri, sır yönetimi, iş kuyrukları ve veri güvenliği; bunlara bağlı hata, kod kalitesi ve performans bulguları.
**Mimari koşul:** Bütün container'lar mevcut ortak Docker network üzerinde kalacak ve gerekli servisler arası iletişim korunacak. Network ayrıştırılması bu raporun düzeltme kapsamı dışındadır.

**Toplam: 60 bulgu; 9 kritik, 34 yüksek, 17 orta. Numaralı alt görev/kapanış kontrolü: 291.**

## 1. Raporun anlamı ve inceleme sınırları

**Kullanıcı talebine göre kapsam revizyonu:** Ortak `vhost_network` kullanımı kabul edilmiş proje mimarisidir; tek başına güvenlik açığı olarak değerlendirilmez. Claude bu raporu uygularken ayrı yönetim/tenant network oluşturmayacak, container'ları mevcut networkten çıkarmayacak ve gerekli container iletişimini kesmeyecektir. Önceki ağ ayrıştırma önerileri ile “DNS/TCP erişimi hiç olmamalı” kabul koşulları çıkarılmıştır.

Panel güvenliği için kullanıcı/servis kimliği, token yetkisi, nesne sahipliği, komut çalıştırma kimliği ve gizli veri erişimi denetlenmeye devam eder. Bir container'ın servise ulaşabilmesi, o serviste bütün yönetim işlemlerini yapma yetkisi olduğu anlamına gelmez. SEC-01 artık Docker yönetim çağrılarındaki kimlik/işlem yetkisi eksikliğini, SEC-02 Caddy yönetim işlemlerindeki erişim sınırını ele alır; bunların çözümü network değiştirmeye bağlanmaz. Panel endpoint'ine kontrol eklemek, aynı işlemin panel dışından kimliksiz çağrılabildiği yolu kendiliğinden kapatmaz; kalan durum kanıtıyla yazılmalıdır.

Öncelik doğrudan Laravel panel bulgularındadır: SEC-04–12, SEC-15–19, BUG-01–10, BUG-19–24, BUG-30–31 ve QA/PERF maddeleri. Panelin sırlarını/verisini ve çalıştırdığı yönetim işlemlerini etkileyen diğer mevcut bulgular bağlantılı kapsam olarak korunmuştur. Ortak network kararından bağımsız olan yetki, dosya, secret ve veri kaybı sorunları çıkarılmamıştır. Bulgu/alt görev ID'leri Claude'a daha önce iletilmiş kayıtlarla eşleşmesi için korunmuştur; bu revizyon yeni bir kaynak kod taraması veya düzeltme doğrulaması değildir.

Bu belge bir düzeltme iş listesidir. Uygulama koduna düzeltme uygulanmadı. Üretim veritabanına bağlanılmadı; Docker servisleri başlatılmadı, durdurulmadı veya değiştirilmedi; gerçek hesaplara saldırı/istismar isteği gönderilmedi. Bulgular kaynak kodu ve yerel yapılandırma üzerinden doğrulandı. Çalışma ağacındaki önceden değiştirilmiş `docker-compose.yaml` incelemeye dahildir; bu değişiklik korunmuştur. Son kontrolde çalışma ağacında ayrıca `P/app/Http/Controllers/{DomainSupervisorController,Api/V1/SupervisorController}.php`, `P/config/panel.php` ve `P/resources/js/Pages/Domains/Show.vue` reload değişiklikleri görüldü; bunlar da korunup ilgili bulgularla karşılaştırıldı. Git dışında tutulan bir dosyadan çıkan bulgu özellikle belirtilir; bunun üretimde aynı olduğunu varsaymayın.

Bu geniş kapsamlı statik inceleme, sistemdeki bütün olası açıkların bulunduğunu veya çalışan üretim sisteminin güvenliğini kanıtlamaz. Canlı ağ erişimi, deploy edilmiş image digestleri, gerçek izinler, veriler ve trafik yükü ayrıca doğrulanmalıdır. Performans bulgularında ölçülmemiş gecikme/RPS sayıları verilmez. Kurulu bağımlılıklar üzerinden belirli CVE'lerin bulunmadığına ilişkin bir iddia yoktur.

**Dosya konumları:** Aşağıdaki yollar depo köküne göredir. `P/` kısaltması her yerde `alpha-panel/web/httpdocs/` anlamına gelir. Örneğin `P/app/Jobs/SendWebhookJob.php:58`, gerçek `alpha-panel/web/httpdocs/app/Jobs/SendWebhookJob.php` dosyasının 58. satırıdır. Satırlar inceleme anına aittir; değişikliklerden sonra sınıf/metot adıyla da bulun.

**Önem:** Kritik = yönetim/host sınırı aşılması veya geniş veri kaybı; Yüksek = ciddi yetki/izolasyon ihlali, güvenlik kontrolünün atlanması veya temel işlevin bozulması; Orta = sınırlı etki, güvenilirlik/performans veya geliştirme güvencesi sorunu. Başlık altındaki önkoşulu mutlaka okuyun. Yöneticiye bilinçli verilen terminal/Docker yetkisi tek başına açık sayılmamıştır.

## 2. Claude için uygulama sözleşmesi

1. Önce kök `CLAUDE.md` ve `P/AGENTS.md` talimatlarını okuyun. Mevcut yerel değişiklikleri koruyun. Depo belgelerindeki sürüm anlatımı yerine `composer.lock`/`package-lock.json` ve çalıştırılan runtime'ı esas alın.
2. Bu dosyadaki her bulgu ID'sini ayrı bir görev olarak izleyin. Bir bulgunun altındaki her düzeltme ve kabul testini ayrı ayrı tamamlayın. Benzer bulgular tek PR'da çözülebilir, fakat ID'ler ve doğrulama kayıtları birleştirilerek kaybedilemez.
3. Önce panelin web/API yetkilendirmesi, ikinci faktörü, token sınırları, güvenli komut çalıştırması ve sır yönetimini düzeltin. Yönetim servisleriyle kimlikli iletişimi mevcut ortak Docker network üzerinde koruyun. Bozuk bir API fonksiyonunu çalışır hale getirmeden önce o fonksiyonun kullanıcı ve token yetkilerini düzeltin; bugün 500 veren bir yol onarıldığında gizli yetki açığı etkinleşebilir.
4. Kullanıcı yetkisi, token ability ve kaynak sahipliği ayrı kontrollerdir. Üçü birlikte uygulanmalıdır. Yalnız UI düğmesi gizlemek veya yalnız token scope daraltmak yeterli değildir.
5. Üretim verisine dokunan test çalıştırmayın. Depoda yasaklanan veri sıfırlama/migration test trait'lerini kullanmayın. Ayrı, önceden hazırlanmış test şeması + test hesabı ve uygun transaction/fixture yaklaşımı kullanın; test hesabına üretim erişimi vermeyin.
6. Varsayılan/stub düzeltmesinin mevcut sunuculara geçişini ayrıca yazın. Kullanıcı UID/GID, servis kimliği, şifre, PHP ini ve Compose bind path değişikliklerinde yeni kurulum ve mevcut kurulum için iki kabul senaryosu gereklidir. Ortak network üyeliği ve gerekli servis keşfi/iletişimi korunmalı; network ayırma veya genel container trafiğini kesme adımı eklenmemelidir.
7. Her ID için sonuç kaydı: değişen dosyalar, giderilen kök neden, çalıştırılan test/çıktı özeti, migration/dağıtım adımı, geri dönüş planı ve kalan engel. Test edilmemiş maddeyi tamamlandı işaretlemeyin. Uygulanamayan maddeyi sessizce atlamayın; neden ve gereken kararı yazın.
8. Yanıt/rapor/log içine gerçek parola, private key, access/refresh token koymayın. Testlerde yalnız sahte işaretleyiciler kullanın. Sızma ihtimali olan sırların rotasyonunu ilgili düzeltmeye dahil edin; rotasyon sırasında ilgili servisleri koordineli güncelleyin.

## 3. Bulgular

<!-- Bulgular aşağıdaki bölümlerde sabit ID ve tamamlanma kutularıyla yer alır. -->

### SEC-01 — Kritik — Docker yönetim API'sinde çağıran servis kimliği ve işlem yetkisi eksik

**Kanıt:** `docker-compose.yaml:631–651`: proxy'de `CONTAINERS=1`, `EXEC=1`, `POST=1`, `IMAGES/NETWORKS/VOLUMES=1`. `P/app/Services/Portainer/PortainerExecClient.php:65–97` bu proxy üzerinden çağıran servis kimliği doğrulanmadan Docker exec oluşturulup başlatıldığını gösteriyor. Endpoint kategorisi filtresi, belirli kullanıcının/servisin belirli container üzerinde işlem yetkisini denetlemiyor. Socket mount'undaki `:ro`, Docker API işlemlerini salt okunur yapmaz. Container'ların aynı networkte olması bulgunun kök nedeni değildir.

**Önkoşul/etki:** Servise ulaşabilen bir kod, panel oturumu veya yetkili servis kimliği sunmadan yönetim komutu verebiliyorsa panelin policy/ability kontrollerini atlayabilir. Diğer container'larda exec ve izin verilen oluşturma/bind işlemleri panel verisi ile host üzerinde etki yaratabilir. Portainer login'i doğrudan kullanılan başka bir Docker API yolunu korumaz.

**Düzeltme:**
- [ ] **SEC-01.01** Ortak networkü koruyarak yönetim API'sine servis kimliği doğrulaması ekleyin; örneğin doğrulanmış istemci sertifikası veya korunan servis credential'ı kullanan bir yönetim aracısı. Mevcut socket-proxy image'ının bu kontrolleri kendiliğinden sağladığını varsaymayın; seçilen çözümün gerçekten desteklediğini doğrulayın. Panel credential'ını hosted kodun environment/dosya alanına vermeyin.
- [ ] **SEC-01.02** Panelin Docker çağrılarını ortak bir yetkili action katmanında toplayın: kullanıcı permission + token ability + nesne sahipliği kontrolünden sonra hedef container, eylem, UID, mount ve capability için sunucu tarafında izin listesi uygulayın. Gerçek servis tarafında da çağıran kimliğinin izinli işlemlerini denetleyin. Sadece PHP controller kontrolü veya proxy endpoint kategorisi yeterli değildir.
- [ ] **SEC-01.03** Kimlik doğrulama katmanının ham Docker API yoluyla atlanamadığını doğrulayın. Ortak networkte sunulan yönetim girişleri aynı kimlik/yetki politikasını uygulamalı; aracının kendi Docker socket/backend bağlantısı kimliksiz alternatif yönetim API'si olarak sunulmamalı. Update-agent/Portainer agent için gerekli yetkileri ayrı servis kimlikleriyle tanımlayın. Bu adım network üyeliği değişikliği veya servisler arası genel bağlantı yasağı istemez.
- [ ] **SEC-01.04** Panel ve yetkili yönetim tüketicilerinin credential dağıtımı/rotasyonunu uygulayın; mevcut meşru Docker işlemlerini geçiş sırasında koruyun. Kullanılmayan yönetim işlemlerini kapatın. Eski kimliksiz yol kalıyorsa bulguyu kapanmış saymayın; kalan kapsamı açık kaydedin.

**Kabul:** Test stack'inde bütün container'lar aynı networkte kalır ve gerekli iletişim sürer. Yönetim API'sine TCP bağlantısı kurulması başarısızlık sayılmaz; eksik/yanlış servis kimliğiyle yönetim isteği reddedilmeli, doğru kimlikte yetki dışı eylem/target reddedilmelidir. Ham alternatif URL üzerinden aynı kontrol atlanamamalı. Panelde yetkisiz kullanıcı isteği Docker çağrısı oluşturmamalı; yetkili normal işlemler başarılı olmalı. Root/host bind gibi özel işlemler yalnız açıkça yetkili sistem action'ında mümkün olmalı; tenant komutuna bu yetkiler geçmemelidir. Gerçek host üzerinde istismar komutu çalıştırmayın. [Docker güvenlik belgesi](https://docs.docker.com/engine/security/)

- [ ] **SEC-01.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-02 — Kritik — Caddy yönetim API'si hosted kodla aynı erişim sınırında

**Kanıt:** `frankenphp/Caddyfile:75–81`, `admin 0.0.0.0:2019`; `docker-compose.yaml:102–147` içinde Caddy ve embedded PHP aynı container'da. Panel dışından kimlik/yetki kontrolünü atlayarak global yönetim isteği yapılabilmesi bu bulgunun konusudur. Ortak network kullanımı değiştirilmez; yalnız Laravel endpoint'ini korumak doğrudan Caddy yönetim yolunu korumaz.

**Önkoşul/etki:** Hosted kod yönetim API'sine HTTP isteği gönderebiliyorsa tüm sitelerin route/root/TLS yapılandırmasını değiştirme, trafik yönlendirme ve hizmet kesintisi mümkündür. PHP `open_basedir` bir ağ erişim kontrolü değildir.

**Düzeltme:**
- [ ] **SEC-02.01** Panelde global Caddy config okuma/değiştirme/reload için açık yönetim permission + token ability uygulayın; domain yetkisi yalnız o domain için güvenli config üretimine izin versin. Servis tarafındaki yönetim girişi de doğrulanmış çağıran kimliğiyle çalışmalı. Ortak network korunmalı; Caddy'nin mevcut admin listener'ına desteklemediği bir token ayarı ekleyerek güvenlik sağlandığını varsaymayın.
- [ ] **SEC-02.02** `ReloadService`, worker restart ve diğer `2019` çağrılarını kontrollü servis kimliği kullanan tek yönetim katmanında toplayın; eski kimliksiz backend'e doğrudan istekle kontrol atlanamasın. Gerekirse backend'i izinli yerel socket üzerinden bu katmana bağlayın; container'ların ortak networkteki meşru servis iletişimini koruyun.
- [ ] **SEC-02.03** Mevcut embedded PHP ile Caddy aynı process/UID içindeyken credential gizleme veya Unix socket izinlerinin aralarında güvenlik sınırı oluşturmadığını doğrulayın. Bunun giderilmesi gerekiyorsa süreç/UID sınırını ayrıca düzenleyin; ayrı bir süreç/container kullanılsa da aynı Docker networkte kalabilir. Bu sınır mevcut haliyle korunacaksa doğrudan hosted-code yönetim erişimini açık kalan mimari bağımlılık olarak kaydedin; yalnız panel permission düzeltmesini tüm bulgunun kapanışı saymayın.

**Kabul:** Aynı networkte normal HTTP/proxy iletişimi ve yetkili panel reload'u başarılı. Yetkisiz panel kullanıcısı ve kimliksiz servis istemcisi global config okuyamıyor/değiştiremiyor; domain düzenleme başka domaini etkilemiyor. Uygulama kontrolü alternatif backend adresi/yerel süreç üzerinden atlanabiliyorsa sonuç kısmen giderilmiş olarak kaydedilir. Ağ erişiminin tamamen kesilmesi kabul koşulu değildir.

- [ ] **SEC-02.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-03 — Kritik — Root kurucu HTTP arayüzü kimlik doğrulamasız sır ve yıkıcı işlem sunuyor

**Kanıt:** `installer/app.py:54–71` `/api/state` ile `asdict(state)` döndürüyor; form ve `generated_secrets` dahil. `:92–104` `/api/reset` doğrudan reset thread'i başlatıyor. `:326–333` arayüzü `0.0.0.0:5000` üzerinde düz HTTP açıyor. `install.sh:24,240` root çalıştırma zinciri; `installer/steps/reset.py:9–23,36–64` MySQL/Postgres/Vaultwarden ve başka kalıcı verileri kaldıran yol. Kodda kurucunun tamamına uygulanan kimlik/CSRF koruması yok.

**Önkoşul/etki:** Kurucu çalışırken port erişilebilir olmalıdır. Böyle bir istemci sırları okuyabilir, kurulumu değiştirebilir veya mevcut verinin kaybını başlatabilir. Portun firewall ile kapalı olduğu bir üretim ortamında uzaktan erişim varsayılmamalıdır.

**Düzeltme:**
- [ ] **SEC-03.01** Varsayılanı loopback yapın; uzaktan kurulum için korunan erişim/tünel kullanın. Her API ve event stream için tek kullanımlık, süreli bootstrap kimlik doğrulaması uygulayın.
- [ ] **SEC-03.02** State cevabını alan izin listesiyle üretin; şifrelerin kendisi yerine yalnız `configured` durumu verin. Cache-control `no-store` uygulayın.
- [ ] **SEC-03.03** Kurulum tamamlanınca arayüzü kapatın ve yeniden kurulum kilidi koyun. Üretim veri dizinlerini temizleyen HTTP reset yolunu kaldırın; bu rapor kapsamında veri silme işlevi çalıştırılmamalıdır.
- [ ] **SEC-03.04** CSRF/origin kontrolü ve install/reset işlemleri arasında tek işlem kilidi kurun; iki thread aynı state/volume üzerinde çalışamasın.
- [ ] **SEC-03.05** Eski state dosyalarında kalmış sırlar için izin/ömrü azaltma ve gerektiğinde rotasyon uygulayın.

**Kabul:** Flask test client + tamamen mock reset/install ile yetkisiz her endpoint reddediliyor; state JSON'unda sentinel parola yok; ikinci işlem 409 dönüyor; completed durumda kurucu yeni iş başlatmıyor. Gerçek reset çağrısı yapılmaz.

- [ ] **SEC-03.06** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-04 — Kritik — API ability kontrolü kullanıcı/tenant yetkilendirmesinin yerine kullanılıyor

**Kanıt:** `P/app/Http/Controllers/Api/V1/AuthController.php:64,105,145` login/refresh/OAuth için `['*']` token oluşturuyor. `P/routes/api.php:57` ortak grup yalnız auth, IP ve idempotency; `P/app/Http/Controllers/Api/V1/ApiController.php:7–23` ve ana `P/app/Http/Controllers/Controller.php` otomatik permission uygulamıyor. Birçok action yalnız route ability'sine güveniyor.

**Kesin örnekler ve taranacak eşdeğerler:**

| API controller | Eksik sınır / etki | Gerekli kontrol |
|---|---|---|
| `ContainerController` | Container list/inspect; inspect ortam sırlarını içerir | panel Docker permission + hedef container izin listesi |
| `AuditLogController`, `TerminalLogController` | Global kayıtlar | ilgili panel permission; gerekli alan redaksiyonu |
| `SettingsController` | Global DNS/ACME/anti-bot/IP/alert ayarları | her ayarın web permission eşdeğeri |
| `BackupController` | Global yedek/Drive dosyası erişimi ve iş başlatma | backup permission + izinli Drive kökü |
| `DockerBindingController` (`index/store/destroy`, `:13–32`) | Domain-servis binding okuma/ekleme/silme; child ilişki kontrolü kullanıcı domain yetkisi değildir | domain policy + servis hedefi sahipliği/izin listesi |
| `DockerServiceController` | Tüm servisler ve modelin ham serialization'ı | kullanıcı permission; gizli env alanlarının filtrelenmesi |
| `DashboardController` | Global operasyonlar; bazı eylemler ayrıca BUG-03 nedeniyle bozuk | dashboard/Docker permission |
| `DomainLogController`, `PhpSettingsController`, `PackageManagerController` | Domain parametresi bulunması tek başına sahiplik kanıtı değil | `DomainPolicy` view/manage alt yetkileri |
| `CronJobController` | `index/store/logs` domain policy uygulamıyor; creator kontrolü domain sahipliğinin yerine geçmiyor | view/manage cron policy + job-domain ilişkisi |
| `SslController` okuma action'ları | `index/show/downloadCsr/export` yalnız domain-cert ilişkisinin bir kısmını denetliyor; domain'e erişim yok | domain SSL/view policy; secret export için ayrı yetki |
| `FtpBanController`, `WafRuleController`, `PhpVersionController`, `MysqlConfigController`, `SystemUpdateController`, `CrowdSecController` | Sistem çapında güvenlik/yönetim uçları | action bazında web ile aynı permission/admin sınırı |

**Somut secret örneği:** `P/app/Http/Controllers/Api/V1/SettingsController.php:107–110` antiBot GET'i `SecuritySetting` modelini ham döndürüyor. `P/app/Models/SecuritySetting.php` içinde `turnstile_secret_key/recaptcha_secret_key` encrypted cast var, `$hidden` yok; dolu değerler serialize edilirken çözümlenmiş secret olarak açığa çıkar. SSL modelinin gizlediği PEM alanları için aynı iddia yapılmıyor.

**Önkoşul/etki:** Mevcut, düşük yetkili bir panel hesabı yeterlidir; public registration gerektiği iddia edilmiyor. Bu hesap API login'inden wildcard alır; `ability:*` doğrulaması kullanıcıyı admin yapmaz, fakat korumasız controller bunu ayırmıyor. BUG-02/03'teki bozuk metotlar bugün bazı etkileri engeller; onarım bu korumalarla birlikte yapılmalıdır.

**Düzeltme:**
- [ ] **SEC-04.01** Bütün `P/routes/api.php` action'ları için route → token ability → kullanıcı permission → domain/nesne sahipliği matrisi çıkarın; yukarıdaki ailelerin her action'ını işaretleyin.
- [ ] **SEC-04.02** Web ve API için ortak policy/action servisi kullanın. Varsayılan reddetme uygulayın; wildcard token kullanıcı permission'ını aşamasın.
- [ ] **SEC-04.03** Login/refresh/OAuth token scope'larını rol ve entegrasyon ihtiyaçlarıyla sınırlandırın. Bu, controller kontrollerinin yerine geçmez.
- [ ] **SEC-04.04** Ham container inspect ve Eloquent model dönüşlerini allowlist API resource'larıyla değiştirin; `Env`, secret/key/password alanlarını maskeleyin.

**Kabul:** Admin, yalnız bir domain sahibi, sadece domain izleyici, hiçbir role sahip olmayan kullanıcı ve dar scope admin tokenı için her action ayrı test edilir. Başka domain ID'si ve global kaynaklar 403/404; izinli akışlar başarılı. [Sanctum belgesi](https://laravel.com/framework/docs/13.x/sanctum#token-abilities), token ability ile uygulama policy'sinin birlikte değerlendirilmesini açıklar.

- [ ] **SEC-04.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-05 — Kritik — Web oturumunun OTP aşaması API üzerinden atlanabiliyor

**Kanıt:** `P/bootstrap/app.php:31,62–63` stateful API açık; `VerifyOTP` yalnız `web` grubuna ekli. `P/routes/api.php:57` API grubunda OTP middleware yok. `P/app/Http/Middleware/VerifyOTP.php:37–49` zaten authenticate edilmiş, `otp=true` hesabın session `otp` alanını denetliyor. Laravel UI parola login'i kullanıcıyı bu aşamadan önce oturuma alıyor.

**Önkoşul/etki:** OTP etkin hesabın parolasını bilen ama ikinci faktörünü bilmeyen kişi, stateful olarak kabul edilen origin/session ile API'ye ulaşır. `/api/ping` gibi GET üzerinden bypass güvenle gözlenebilir; yetkili API eylemlerine erişim etkisi hesap yetkisiyle büyür. CSRF bir ikinci faktör kontrolü değildir.

**Düzeltme:**
- [ ] **SEC-05.01** Kimliği doğrulanmış oturum ile ikinci faktörü tamamlanmış oturumu açıkça ayırın. Tamamlanmamış session'ı tüm panel API'sinde reddeden JSON uyumlu middleware ekleyin.
- [ ] **SEC-05.02** Bearer token login'inde ikinci faktörün issuance anında doğrulandığını koruyun; session'a ait kontrolü körlemesine token isteklerine uygulamayın.
- [ ] **SEC-05.03** Lock-screen, WebAuthn ve impersonation yollarının aynı doğrulama durumunu tüketmesini sağlayın; API logout da stateful oturumu doğru kapatsın.

**Kabul:** `otp=true` kullanıcı parola sonrası `/api/ping` ve en az bir korunan kaynakta reddedilir; doğru OTP/passkey sonrası başarılı; kilitlemeden sonra yeniden reddedilir. Normal bearer token akışı bozulmaz.

- [ ] **SEC-05.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-06 — Yüksek — Dar kapsamlı admin tokenı sınırsız token üretebiliyor

**Kanıt:** `P/app/Http/Controllers/Api/V1/ApiTokenController.php:44–70` yalnız `ensureAdmin` sonrası istemcinin `abilities` listesini ve sınırsız expiration'ı kabul ediyor. API token yönetimi route'larında parent token scope/ömür/IP kısıtını devretme kontrolü yok; `:93–124` başka token IP kurallarını da yönetebiliyor.

**Önkoşul/etki:** Admin'e ait sadece okuma gibi dar kapsam bir tokenın ele geçirilmesi, wildcard/süresiz/IP kısıtsız yeni token oluşturmayı mümkün kılar. Kaynak tokenı iptal etmek yeni tokenı otomatik kaldırmaz.

**Düzeltme:**
- [ ] **SEC-06.01** Token yönetimine ayrı kullanıcı permission ve token ability koyun; yalnız okuma tokenından mint/revoke/IP değiştirme taleplerini reddedin.
- [ ] **SEC-06.02** Delegation desteklenecekse child ability'leri parent'ın alt kümesi, son kullanımı parent'tan kısa/eşit ve IP politikası daha dar/eşit olsun. `*` özel durumunu açık değerlendirin.
- [ ] **SEC-06.03** Parent-child ilişkisi ve revocation zinciri kaydedin veya mint işlemini yakın zamanda ikinci faktör doğrulanmış interaktif oturumla sınırlandırın.
- [ ] **SEC-06.04** Token issuance cevabını SEC-08 cache'inden çıkarın.

**Kabul:** `domains:read` admin tokenı `['*']`, süresiz veya daha geniş IP'li token üretemez; diğer tokenların IP/revoke ayarlarını değiştiremez; izinli yönetici oturumu normal token oluşturabilir.

- [ ] **SEC-06.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-07 — Yüksek — Handshake global webhook kaydını herhangi bir hesapla değiştirebiliyor

**Kanıt:** `P/routes/api.php:60`; `P/app/Http/Controllers/Api/V1/HandshakeController.php:19–51`: admin/permission/ability/ownership kontrolü olmadan URL üzerinden global `WebhookEndpoint::updateOrCreate`; aynı URL'nin secret'ı ve event listesi değiştiriliyor. Son aktif refresh token seçimi de entegrasyon/oturum kimliğine bağlı değil.

**Etki:** Düşük yetkili hesap mevcut entegrasyonu bozabilir, endpoint secret'ını değiştirebilir. BUG-07 wildcard davranışı düzeltildiğinde izinsiz global olay aboneliği/veri aktarımı da çalışır hale geleceğinden iki düzeltme birlikte yapılmalıdır. URL validasyonu bir hedefin bu kullanıcı/entegrasyon tarafından kullanılmasına izin verildiğini doğrulamaz; keyfi hedef seçimi paneli yetkisiz iç yönetim isteği gönderen bir aracıya dönüştürebilir. Yetkili iç servis webhook'ları açık bir hedef politikasında desteklenmelidir.

**Düzeltme:**
- [ ] **SEC-07.01** Handshake'e entegrasyon yönetimi permission + token ability ekleyin; kayıtları entegrasyon ve sahibine bağlayın. Global URL eşleşmesiyle başka kaydı değiştirmeyin.
- [ ] **SEC-07.02** Refresh tokenı `en son kullanıcı tokenı` üzerinden seçmeyin; ilgili oturum/entegrasyonla ilişkilendirin.
- [ ] **SEC-07.03** Webhook göndericisinde kullanıcı/entegrasyon bazlı izinli hedef politikası uygulayın: scheme, servis/hostname, port, path ve gereken servis kimliği doğrulansın. Yetkili iç container hedefleri açıkça tanımlanabilsin; bütün private IP'leri veya container iletişimini genel olarak yasaklamayın. URL kaydında ve teslim sırasında DNS çözümlemesi ile redirect'i yeniden denetleyin; izinsiz Docker/Caddy yönetim işlemlerine yönlendirme reddedilsin. Dış webhook'larda HTTPS kullanın; iç servislerde transport/kimlik politikasını açık tanımlayın.

**Kabul:** Yetkisiz hesap handshake yapamaz; A entegrasyonu B kaydının secret'ını değiştiremez; aynı kullanıcının iki entegrasyonu ayrıdır. Açıkça izinli iç servis webhook'u aynı networkte çalışır; izin verilmemiş yönetim hedefi ve izinli URL'den izinsiz hedefe DNS/redirect değişimi mock testte reddedilir. Özel IP olması tek başına ret nedeni değildir.

- [ ] **SEC-07.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-08 — Yüksek — Idempotency cache yetki sınırlarını ve işlem kimliğini karıştırıyor

**Kanıt:** `P/app/Http/Middleware/IdempotencyKey.php:41–63` cache anahtarı yalnız kullanıcı ID + header hash. Token, HTTP yöntem, path, body hash yok; cache hit'te `$next()` çağrılmıyor. `P/routes/api.php:57` middleware, action bazlı ability/controller kontrollerinden önce. `Cache::get` ve `Cache::put` arasında atomik işlem/lock yok; JSON olmayan/redirect cevaplar da JSON'a çevrilerek saklanıyor.

**Doğrulandı:** Veritabanı ve uygulama boot'u olmadan gerçek middleware + ArrayStore ile önce sahte `/api/api-tokens` POST cevabı kaydedildi; aynı kullanıcı/anahtarla `/api/domains` DELETE çağrısı **201**, `controller_calls=0` ve önceki sentinel token cevabını verdi. Gerçek sır veya API kullanılmadı.

**Etki:** Aynı kullanıcının başka scope tokenı, anahtarı biliyorsa önceki hassas cevabı alabilir; farklı işlem yanlışlıkla yapılmış görünür. Paralel retry iki işlemi de çalıştırabilir. Token issuance cevabı 24 saat düz içerik olarak cache'de kalabilir.

**Düzeltme:**
- [ ] **SEC-08.01** Kimlik, yetki ve sahiplik kontrolü cache replay'den önce tamamlanmalı; cached cevap yetkilendirmeyi kısa devre etmemeli.
- [ ] **SEC-08.02** Kullanıcı/istemci-token, yöntem, canonical route/resource ve request payload hash'ini bağlayın; aynı key farklı işlem/payload için 409 dönsün.
- [ ] **SEC-08.03** Atomik claim/lock, `in_progress/completed` durumu ve işlem ömrüne uygun TTL kullanın; eşzamanlı istekte yalnız bir yan etki oluşsun.
- [ ] **SEC-08.04** Token/secret/SSO cevaplarını cache dışı bırakın. Yalnız belirlenen JSON eylemlerini destekleyin; content-type ve gereken response header'larını doğru saklayın.

**Kabul:** Farklı route/yöntem/payload/token tekrarları izole veya 409; dar token yetkisiz cevabı alamıyor; iki paralel aynı istek tek yan etki üretiyor; başarısız veya yarıda kalan işlem yanlış başarılı replay üretmiyor; secret sentinel cache'de yok.

- [ ] **SEC-08.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-09 — Kritik — Tenant uygulama kodu bazı yönetim akışlarında root çalışıyor

**Kanıt:** `P/app/Http/Controllers/DomainSupervisorController.php:283–297` ve `P/app/Http/Controllers/Api/V1/SupervisorController.php:212–225` optimize komutunda exec user yok. Web controller `:341–366` ve API `:254–277` artisan için yalnız doğrudan `ftpUser` kullanıyor; subdomain parent fallback'i yok. `P/app/Services/SupervisorConfigService.php:108–128`, doğrudan FTP hesabı yoksa `user=` yazmıyor. `P/app/Http/Controllers/DomainPackageManagerController.php:41,145,271` ve API `P/app/Http/Controllers/Api/V1/PackageManagerController.php:29,70,111` exec çağrılarında da user verilmemiş. `P/app/Services/Portainer/PortainerExecClient.php:21,53–63`, null user halinde Docker payload'ından User alanını çıkarıyor; image/entrypoint root varsayılanı kullanılıyor.

**Önkoşul/etki:** Kendi `artisan`, `composer.json` veya `package.json` dosyasını değiştirebilen ve ilgili işlevi çağırabilen tenant, sabit görünen optimize/build/install komutuyla kendi kodunu root çalıştırabilir. Komut adını allowlist yapmak lifecycle script'lerini güvenilir yapmaz. Gereğinden yüksek UID ve bu sürecin okuyabildiği ortak dosya/sırlar nedeniyle diğer siteler ile panel verisi etkilenebilir; network paylaşımı bu bulgunun nedeni değildir.

**Düzeltme:**
- [ ] **SEC-09.01** Tüm tenant kodu çalıştıran yolları tek `DomainExecutionContext` benzeri çözümleyiciye taşıyın: doğru container, canonical root, parent dahil etkin UID/GID, izinli environment ve timeout.
- [ ] **SEC-09.02** FTP/OS kullanıcı çözümlenemediğinde işlemi reddedin; null/root'a düşmeyin. Supervisor'a zorunlu kullanıcı yazın.
- [ ] **SEC-09.03** npm/composer/artisan/SSR/queue/scheduler dahil tüm süreçleri tenant UID'sinde ve yönetim sırları olmadan çalıştırın. Root gereken config yazımı/reload'u ayrı dar kapsam aracıda tutun.
- [ ] **SEC-09.04** Önceden root üretilmiş dosyaların sahipliğini güvenli, domain sınırını aşmayan geçişle düzeltin.

**Kabul:** Apex, subdomain, linked domain, FTP hesabı olmayan domain ve her PHP servis tipi için komut payload'ında root olmayan beklenen UID görülür; sentinel lifecycle script'i yalnız kendi test dizinine yazabilir; parent hesap yoksa hiçbir exec gönderilmez.

- [ ] **SEC-09.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-10 — Kritik — Custom Caddy bypass tenant'ın dosya kökünü ve upstream sınırını kaldırıyor

**Kanıt:** `P/app/Http/Requests/UpdateDomainRequest.php:90–97` `SafeCaddyDirectives(strict:false)` kullanıyor. `P/app/Rules/SafeCaddyDirectives.php:66–82,113–143` lenient modda `root`, `file_server`, `reverse_proxy` serbest. `P/app/Services/Domain/CaddyConfigRenderer.php:614–634` custom directive'leri doğrudan döndürüp normal handler üretimini atlıyor. `DomainPolicy::update` yalnız `domain.edit` + sahiplik kontrolü; ayrı global proxy yetkisi yok.

**Önkoşul/etki:** Kendi domainini düzenleyebilen kullanıcı, başka tenant'ın dizinini `root` olarak seçip dosyalarını servis edebilir veya panelin verdiği domain düzenleme yetkisini kullanarak yetkisiz yönetim API'sine reverse proxy tanımlayabilir. PHP `open_basedir` Caddy'nin statik dosya sunmasını engellemez. Ayrıca satır token denylist'i yapılandırma ağacının domain bloğunda kaldığını kanıtlamaz.

**Düzeltme:**
- [ ] **SEC-10.01** Tenant için ham Caddy metni yerine yapılandırılmış, izinli handler seçenekleri üretin; canonical root'u ilgili domain dizinine sabitleyin.
- [ ] **SEC-10.02** Proxy hedeflerini kullanıcının domain'ine atanmış servis ve portlardan seçtirin; aynı networkteki meşru iç uygulama hedefleri desteklenmeli. Domain düzenleyicisinin yetkisi dışında kalan Docker/Caddy yönetim API'sini veya başka tenant hedefini seçmesini engelleyin. Bu kural panelin config üretiminde uygulanır; container iletişimine genel ağ yasağı konmaz. Global yöneticiye özel hedef gerekiyorsa ayrı permission ve açık servis bağlamı isteyin.
- [ ] **SEC-10.03** Serbest konfigürasyon gerekiyorsa bunu açıkça global yönetici yetkisine ayırın. Sadece yeni denylist kelimeleri ekleyerek kapatmayın.
- [ ] **SEC-10.04** Mevcut custom metinleri migration'da denetleyin; generated config'de blok kapsamı, güvenlik handler'ları ve diğer domainlerin değişmediğini doğrulayın.

**Kabul:** İki sahte tenant ile B'nin sentinel dosyasını A kökü/proxy'si üzerinden okuma reddedilir; sınırlı domain kullanıcısı SEC-01/02 yönetim işlemlerini upstream olarak delege edemez. Domain'e atanmış iç container upstream'i aynı networkte çalışır; brace/block kaçış testleri ve yetkili global yönetici akışı ayrıca geçer.

- [ ] **SEC-10.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-11 — Yüksek — PHP-FPM ayarına satır enjekte edilerek sabit güvenlik ayarı değiştirilebiliyor

**Kanıt:** `P/app/Http/Requests/PhpSettingsRequest.php:21,32` `error_reporting`/`disable_functions` yalnız string/max doğrulaması alıyor. `P/app/Services/Domain/PhpFpmConfigRenderer.php:51–53,69–73` önce `php_admin_value[open_basedir]`, sonra kullanıcı değerleri ham satır olarak yazılıyor. Satır sonu içeren değer yeni FPM direktifi oluşturabilir. Web PHP ayar yolu bunu `writePhpFpmConfig` ile diske yazıyor.

**Önkoşul/etki:** PHP ayarı düzenleme yetkisi ve FPM kullanan domain gerekir. Güvenlik direktifi override veya geçersiz pool ile FPM restart/reload hatası, aynı PHP sürümündeki diğer siteleri etkileyebilir. API apply eksikliği BUG-05'te ayrıca ele alınmıştır.

**Düzeltme:**
- [ ] **SEC-11.01** `error_reporting` için desteklenen sabit/bitmask seçeneklerini; `disable_functions` için yalnız PHP fonksiyon adlarından oluşan canonical listeyi kabul edin. CR/LF/NUL ve yapılandırma delimiter'larını reddedin.
- [ ] **SEC-11.02** Sistem tarafından zorunlu yasaklanan fonksiyonları tenant'ın azaltamadığı ayrı policy olarak uygulayın; güvenlik tabanını kullanıcı tercihiyle değiştirmeyin.
- [ ] **SEC-11.03** Renderer'da ikinci doğrulama ve candidate pool syntax testi yapın; başarısız dosyayı etkinleştirmeyin, önceki dosyayı koruyun.

**Kabul:** CR/LF, yeni `php_admin_value`, yeni pool bloğu, delimiter ve geçersiz fonksiyon listeleri 422; eski DB satırı renderer'a ulaşsa da reddedilir; normal ayar değişikliği çalışır ve diğer pool'lar etkilenmez.

- [ ] **SEC-11.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-12 — Yüksek — ZIP oluşturma iç symlink'lerde domain dizini kontrolünü atlıyor

**Kanıt:** `P/scripts/fm-worker.php:181–196` `addPathToZip` recursive alt öğeleri yeniden `resolvePath` kontrolünden geçirmiyor; symlink dosya `ZipArchive::addFile` ile okunuyor. `:361–382` yalnız başlangıç seçimini doğruluyor. Normal dosya okuma yolunda bulunan jail kontrolü arşivlemede eşdeğer uygulanmıyor.

**Önkoşul/etki:** Domain dizininde dışarıdaki okunabilir bir dosyaya symlink oluşturabilen kişi, symlink'in parent klasörünü ZIP'leyerek içeriği alabilir. Etki worker OS kullanıcısının okuyabildiği dosyalarla sınırlıdır; root-only dosyaların koşulsuz okunabildiği iddia edilmiyor.

**Düzeltme:**
- [ ] **SEC-12.01** Recursive fonksiyona gerçek jail root'u geçirin. Her alt öğede `lstat`/`realpath` ile containment doğrulayın; varsayılan olarak symlink'leri arşivden reddedin veya link olarak saklayın, hedefini takip etmeyin.
- [ ] **SEC-12.02** Aynı kuralı tüm file manager backend'lerinde compress/copy/move/recursive tarama yollarına uygulayın; hata ve atlanan dosyaları açık raporlayın.
- [ ] **SEC-12.03** Kaynak değişimi yarışlarını azaltmak için tenant UID'si ve mümkünse OS seviyesinde dosya sınırı kullanın; yalnız string prefix kontrolüne güvenmeyin.

**Kabul:** Geçici dizinde jail dışına işaret eden sentinel symlink doğrudan read ve parent ZIP yolunda okunamaz; iç normal dosya arşivlenir; symlink klasör/döngü/broken link testleri geçer. Test gerçek sır kullanmaz.

- [ ] **SEC-12.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-13 — Yüksek — Global Cloudflare tokenı hosted PHP environment'ında

**Kanıt:** `docker-compose.yaml:111–118`, shared `frankenphp` container'ına `CF_API_TOKEN` veriyor. Bu container aynı zamanda tenant PHP çalıştırıyor. `frankenphp/Caddyfile:68–72` global Cloudflare TLS bloğu yorumlu; environment'daki tokenın tenant işlemlerine verilmesi ayrıcalık sınırını gereksiz genişletiyor.

**Etki:** Container ortamını okuyabilen hosted PHP kodu tokenı alabilir; dosya jail'i bunu engellemez. Etki tokenın Cloudflare'da tanımlı zone/hesap izinlerine bağlıdır.

**Düzeltme:**
- [ ] **SEC-13.01** DNS/ACME secret'larını tenant runtime'dan çıkarın; yalnız panel/ACME worker'a verin. Gerekirse zone kapsamlı ayrı tokenlar kullanın.
- [ ] **SEC-13.02** Tüm hosted process environment'ını allowlist ile oluşturun; yalnız `CF_API_TOKEN` adını gizlemekle sınırlamayın.
- [ ] **SEC-13.03** Mevcut tokenı döndürün, eski tokenı iptal edin ve container'ları yeni secret dağılımıyla yeniden oluşturun.

**Kabul:** İzole hosted PHP'de gerçek değer basmadan sadece değişken varlık kontrolü yapılır ve token bulunmaz; DNS/sertifika yenileme yetkili bileşende çalışır.

- [ ] **SEC-13.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-14 — Yüksek — phpMyAdmin ve FTP için gereğinden geniş panel veritabanı/sır erişimi

**Kanıt:** `docker-compose.yaml:408–416`, phpMyAdmin'e panel DB kullanıcı/parolası ve `PANEL_APP_KEY` veriyor. `installer/steps/mysql_setup.py:51–55` panel hesabına `*.*` ve GRANT OPTION; `phpmyadmin/signon.php:60–61,124–129` bu yetki/sırları SSO için kullanıyor. Aynı installer `:62–64` FTP hesabına tek tablo yerine panel şemasının tamamında SELECT veriyor.

**Önkoşul/etki:** phpMyAdmin/FTP bileşeninin ele geçirilmesi, ihtiyaç duyduğu SSO/FTP verilerinin ötesinde panel verisi ve phpMyAdmin için uygulama şifreleme anahtarına erişim sağlar. Panel'in database provisioning yapması yönetim yetkisi gerektirir; çözüm bu yetkiyi bütün yardımcı uygulamalara dağıtmak değildir.

**Düzeltme:**
- [ ] **SEC-14.01** phpMyAdmin SSO için panelin APP_KEY'ini paylaşmayan, süreli/tek kullanımlık server-to-server token exchange tasarlayın; gerekirse yalnız SSO tablosuna erişen ayrı hesap kullanın.
- [ ] **SEC-14.02** Panel normal DB erişimini, ayrı yetkili DB provisioning aracısından ayırın; runtime ihtiyacını test etmeden panel hesabının bütün yetkilerini körlemesine kaldırmayın.
- [ ] **SEC-14.03** FTP hesabını gerçekten kullanılan FTP tablolarındaki SELECT ile sınırlandırın; eski schema-wide grant'i de geri alın.
- [ ] **SEC-14.04** Mevcut kurulum için ayrı kullanıcı oluşturma, grant geçişi, secret dağıtımı ve rotasyon adımlarını uygulayın.

**Kabul:** FTP hesabı users/token/backup settings tablolarını okuyamıyor; PMA container'ında panel APP_KEY'i yok; SSO ve normal DB kullanıcı oluşturma meşru yolları çalışıyor; yanlış/eski/ikinci kez kullanılan SSO tokenı reddediliyor.

- [ ] **SEC-14.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-15 — Yüksek — İkinci faktör için üç ayrı durum alanı tutarsız kullanılıyor

**Kanıt:** `P/app/Models/User.php:68–81` TOTP doğrulaması yalnız `two_factor_confirmed=true` yapıyor. `P/app/Http/Middleware/VerifyOTP.php:37` web zorunluluğunu `otp` ile ölçüyor. API login `two_factor_confirmed` kullanıyor. `P/routes/web.php:338–341` Fortify enable/disable controller'ına bağlı; Fortify action'ları `two_factor_secret/recovery_codes/confirmed_at` değiştirip özel `otp/two_factor_confirmed` alanlarını güncellemiyor. `FortifyServiceProvider` listener'ları yalnız audit yazıyor.

**Etki:** TOTP doğrulansa bile `otp=false` kullanıcı web'de ikinci faktör istemeden girebilir; disable sonrasında özel confirmed bayrağı açık kalırsa API null secret'ı decrypt etmeye çalışabilir. Arayüz ve backend farklı etkinlik durumları gösterir.

**Düzeltme:**
- [ ] **SEC-15.01** Enrollment-pending, enabled-and-confirmed, challenge-passed durumları için tek tutarlı model belirleyin; ikinci faktörün etkinliği ile oturum doğrulamasını ayırın.
- [ ] **SEC-15.02** Enable/confirm/disable/passkey ekleme-silme işlemlerini aynı state transition servisine yönlendirin. Disable bütün ilgili alanları ve oturum doğrulamasını tutarlı güncellesin.
- [ ] **SEC-15.03** Mevcut çelişkili satırlar için veri koruyan migration/onarma yazın; otomatik güvenlik zayıflatması yerine gerektiğinde kontrollü yeniden enrollment uygulayın.
- [ ] **SEC-15.04** `confirmTwoFactorAuth` null/geçersiz secret için 500 yerine kontrollü ret versin; sadece doğrulama amacıyla kullanıcı durumunu yazan yan etkiyi ayırın.

**Kabul:** Yeni kullanıcı, onay bekleyen TOTP, etkin TOTP, disable, yeniden enable ve passkey kombinasyonları web/API/lock-screen'de aynı sonucu verir; hiçbir null secret yolu 500 üretmez.

- [ ] **SEC-15.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-16 — Yüksek — Login IP politikası alternatif giriş yollarında uygulanmıyor

**Kanıt:** Web parola login'i `P/app/Http/Controllers/Auth/LoginController::middleware` ve WebAuthn route'ları `CheckLoginIp` kullanıyor. `P/routes/api.php:49–54`, API login/refresh/token'da aynı kontrol yok; `P/app/Http/Controllers/Api/V1/AuthController::login` yalnız parola/passkey/TOTP denetliyor. `OAuthController::submit` captcha kontrol etse de `isIpAllowed` çağırmıyor. `api.token.ip` yalnız mevcut personal token kurallarını denetler, global login politikası değildir.

**Etki:** IP whitelist/blacklist ile web girişinden engellenen kaynak, geçerli kimlik bilgileriyle API veya OAuth girişinden erişebilir. API'ye tarayıcı CAPTCHA eklemek tek zorunlu çözüm değildir; makine istemcileri için eşdeğer politika belirlenmelidir.

**Düzeltme:**
- [ ] **SEC-16.01** Global login IP politikasını parola/WebAuthn/API/OAuth girişlerinin ortak katmanında uygulayın; refresh sırasında uygulanacak politika açık ve testli olsun.
- [ ] **SEC-16.02** Panelin istemci IP'sini çözerken yalnız gerçek ingress proxy'lerin ilettiği header'lara güvenmesini sağlayın; bütün özel IP bloklarını trusted proxy saymayın. Aynı networkteki başka bir servisin sahte forwarding header'ıyla login IP politikasını atlayamadığını test edin. Bu header güveni kontrolüdür; network üyeliği ve servis bağlantıları korunur.
- [ ] **SEC-16.03** Reddedilen login için hassas veri içermeyen ortak audit ve hesap+IP rate limit uygulayın.

**Kabul:** Aynı engelli test IP'si web, API ve OAuth'ta ret alır; izinli IP çalışır; sahte forwarded header sonucu değiştirmez; refresh politikası belgelenen şekilde uygulanır.

- [ ] **SEC-16.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-17 — Yüksek — Refresh token ve OAuth code tüketimi atomik değil

**Kanıt:** `P/app/Http/Controllers/Api/V1/AuthController.php:87–116,129–157`: token/code okunuyor, `isValid` kontrolü yapılıyor, ardından bağımsız update ve yeni token üretimi geliyor; transaction/row lock/koşullu tek tüketim yok. Aynı refresh/code eşzamanlı iki istekte geçerli görülebilir.

**Etki:** Tek kullanım ve rotation sözleşmesi bozulur; bir kullanımdan birden fazla token zinciri oluşabilir. Çalınmış refresh tokenın yeniden kullanımı için family revocation algısı da yok. Bu bir eşzamanlılık bulgusudur; gerçek hesapta replay yapılmadı.

**Düzeltme:**
- [ ] **SEC-17.01** Token/code tüketimini transaction içinde satır kilidi veya `used/revoked IS NULL` koşullu update ile tek kazanana indirin; token üretimini aynı atomik birime bağlayın.
- [ ] **SEC-17.02** Refresh family/session kimliği ve replay tespiti ekleyin; yeniden kullanımda ilgili zincirin nasıl iptal edileceğini belirleyin.
- [ ] **SEC-17.03** Token üretimi ortasında hata olduğunda code'un kaybolmaması veya yarım zincir kalmaması için rollback testleri ekleyin.

**Kabul:** Aynı token/code'a eşzamanlı iki izole test isteğinden yalnız biri başarılı; ikinci istek token üretemiyor; hata enjeksiyonunda tutarlı durum korunuyor.

- [ ] **SEC-17.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-18 — Yüksek — Özel secret alanları ve komut satırları loglara sızabiliyor

**Kanıt:** `P/app/Providers/TelescopeServiceProvider.php:22–29,37–49`, production'da 500 istekleri kaydediyor ve sadece ek `_token/cookie/csrf` maskeleri tanımlıyor. Telescope kendi varsayılanında `password`, `password_confirmation`, `authorization` alanlarını zaten maskeler; bunlar için yanlış pozitif iddia edilmiyor. `private_key`, `ftp_password`, `webhook_secret`, `eab_hmac_key`, `refresh_token`, iç içe environment sırları için ek koruma yok. `P/app/Http/Controllers/Api/V1/SslController.php:89–95` private key aldıktan sonra eksik metot nedeniyle 500 üreten somut yol. `P/app/Services/Portainer/PortainerExecClient.php:51` tüm komutu logluyor. `P/app/Jobs/BackupUploadJob.php:325–338,446–456` DB parolasını shell komut string'ine koyuyor.

**Etki:** Log/Telescope verisi veya process list erişimi, encrypted DB alanlarının dışındaki düz secret kopyalarına ulaşabilir. `TELESCOPE_ENABLED=true` örnek varsayılandır; üretimde kapalıysa Telescope kısmının çalışma önkoşulu yoktur.

**Düzeltme:**
- [ ] **SEC-18.01** Uygulamaya özgü secret alan envanteri çıkarın; request/response/job/client HTTP/log kanallarında recursive redaksiyon uygulayın. Token/key dönen ve alan adı öngörülemeyen payload'ları kaydetmeyin.
- [ ] **SEC-18.02** Komut logunu tam argv yerine eylem/target/sonuç/metrik ile sınırlandırın. Şifreyi argv/shell string yerine uygun env aktarımı veya 0600 geçici client config/stdin kullanarak verin; logda da maskeleyin.
- [ ] **SEC-18.03** Mevcut log/Telescope retention ve erişimini denetleyin; sızdığı doğrulanan sırları döndürün. Üretimde gerekli olmayan watcher'ları kapatın.

**Kabul:** Sahte secret sentinel ile 500, timeout, başarısız exec ve token response testlerinde hiçbir log/Telescope/cache/argv kaydı düz sentinel içermez; hata referansı ve teşhis için gerekli güvenli bilgiler kalır. [Telescope belgesi](https://laravel.com/framework/docs/13.x/telescope) watcher ve filtreleme yapılandırmasına başvuru sağlar.

- [ ] **SEC-18.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-19 — Orta — Giriş yöntemi uçları hesap varlığını ve bazı e-postaları açıklıyor

**Kanıt:** `P/app/Http/Controllers/Auth/LoginController.php:77–105` gerçek hesap için passkey/TOTP durumunu, passkey varsa e-postayı döndürüyor; `P/routes/web.php:122–124` `login/methods` üzerinde throttle yok. `P/app/Http/Controllers/OAuthController.php:34–57` `found=true/false`, passkey ve password varlığını ayırıyor; bu yol rate limitli olsa da açık enumeration sağlıyor.

**Etki:** Kullanıcı adları/e-postalar ve giriş yöntemleri denenerek hedef hesap envanteri çıkarılabilir. Kimlik bilgisi olmadan hesap ele geçirme iddiası yoktur.

**Düzeltme/kabul:**
- [ ] **SEC-19.01** Login UX'in gerektirdiği minimum veriyi belirleyin; kullanıcı adına karşı e-posta döndürmeyin, bilinmeyen hesapla ayırt edilemeyen genel seçenek cevabı tasarlayın veya keşfi ilk doğrulamadan sonraya taşıyın.
- [ ] **SEC-19.02** Her iki yola hesap+IP rate limit ve abuse kaydı koyun. Var/yok, passkey/TOTP ve throttle testlerini ekleyin; ölçülebilir cevap şekli farkı kalmadığını doğrulayın.

- [ ] **SEC-19.03** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### SEC-20 — Yüksek — Kurucu tek IPv6 yönetici IP'sini geniş `/32` ağına dönüştürüyor

**Kanıt:** `installer/steps/caddyfile.py:17–26` slash içermeyen her IP'ye `/32` ekliyor. `:69–71,81–95` bu değer Jenkins'in client_ip allowlist'ine giriyor. Gerçek saf formatter kontrolünde `2001:db8::1` → `2001:db8::1/32`; istenen tek IPv6 adresi için `/128` gerekir. Caddy CIDR'ı kabul ettiğinde kural amaçlanan tek adres yerine geniş ağı kapsar; geçersiz config olarak reddederse kurulum/reload hatası oluşur. Jenkins'in kendi login'i ayrı koruma olarak kalır.

**Düzeltme/kabul:**
- [ ] **SEC-20.01** `ipaddress.ip_address/ip_network` ile tür ve CIDR doğrulayın: yalın IPv4 /32, yalın IPv6 /128; kullanıcının açık verdiği CIDR'ı canonical biçimde koruyun. Hatalı girdiyi ham config'e yazmayın.
- [ ] **SEC-20.02** Form/backend validation ve tüm admin IP renderer'larını aynı yardımcıya taşıyın; empty allowlist davranışı açıkça tanımlı olsun.
- [ ] **SEC-20.03** Existing generated Jenkins Caddyfile için güvenli migration hazırlayın; yalın IPv6'dan türemiş yanlış ağları kayıtlı kaynak veriye göre düzeltin. Bilinçli verilmiş geniş CIDR'ı tahminle daraltmayın.
- [ ] **SEC-20.04** IPv4/IPv6 tek adres, explicit CIDR, karışık liste ve invalid değer testleri; staging Caddy doğrulaması ve listede olmayan komşu IPv6 adresinin reddi geçsin.

- [ ] **SEC-20.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-01 — Yüksek — Belgelenen `/api/v1` prefix'i çalıştırılan route'larda yok

**Kanıt:** `P/bootstrap/app.php:24–29` `apiPrefix` belirtmiyor; kurulu Laravel `ApplicationBuilder::withRouting` varsayılanı `api`. `P/routes/api.php` içinde v1 dış grubu yok. Buna karşılık `API-DOCUMENTATION.md:51,78` ve `P/tests/Feature/Api/PingTest.php:15,24` `/api/v1` kullanıyor. `P/config/scramble.php:14` de `api` tarıyor. İncelenen Caddy config'lerinde bu farkı kapatan v1 rewrite saptanmadı.

**Etki:** Belgeleri/Postman/testleri izleyen istemciler 404 alır; gerçek auth/ability testleri hedef route'u çalıştırmaz.

**Düzeltme:**
- [ ] **BUG-01.01** Tek canonical API prefix kararı verin; belgelenen v1 sözleşmesini koruyacaksanız route grubunu `/api/v1` yapın. Mevcut `/api` istemcileri için gerekiyorsa süreli, aynı middleware'li alias geçişi uygulayın.
- [ ] **BUG-01.02** Bootstrap, route isimleri, Scramble/OpenAPI, Postman, frontend çağrıları, entegrasyon örnekleri ve bütün feature testlerini aynı kaynaktan hizalayın. Docs endpoint'ini yanlışlıkla taşıyıp erişilemez kılmayın.
- [ ] **BUG-01.03** Prefix testini fixture DB gerektirmeyen route registry testiyle ve yetkisiz 401/izinli 200 testiyle doğrulayın.

**Kabul:** Belgelenen ping/auth/domain URL'leri 404 değil beklenen cevapları verir; eski alias varsa aynı yetki kontrollerini taşır; API docs gerçek prefix'i gösterir.

- [ ] **BUG-01.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-02 — Yüksek — Yedi route olmayan controller action'ına bağlı

**Kanıt/eksiksiz işlem listesi:** Statik route→controller taraması yapıldı; ana controller'larda bunları karşılayan `__call`/miras action yok.

| Yapılacak | `P/routes/api.php` | Eksik action | Referans alınacak web davranışı |
|---|---:|---|---|
| [ ] BUG-02.01 | 263 | `DockerHubController::imageConfig` | `DockerHubController` image config inceleme akışı |
| [ ] BUG-02.02 | 278 | `FirewallController::preview` | Web firewall preview; değişiklik yapmadan aday kural gösterimi |
| [ ] BUG-02.03 | 301 | `CrowdSecController::data` | Web CrowdSec data/summary |
| [ ] BUG-02.04 | 323 | `BackupController::restart` | Web restart; BUG-14 düzeltilmiş yaşam döngüsü |
| [ ] BUG-02.05 | 327 | `BackupController::disconnect` | Web Drive bağlantı kesme ve token temizleme |
| [ ] BUG-02.06 | 342 | `SystemUpdateController::index` | Web update durumu/listesi |
| [ ] BUG-02.07 | 405 | `SettingsController::runAlertCheck` | Web health/alert check action'ı |

**Düzeltme:** Her satır için mevcut web action'ının iş mantığını ortak servise çıkarıp API'ye doğru response contract'ıyla bağlayın; boş 200 dönen stub yazmayın. SEC-04 yetkisini, request validation'ını, yan etki ve hata durumunu birlikte uygulayın. Artık desteklenmeyecek yol varsa açıkça deprecate edip doküman/testlerini güncelleyin.

**Kabul:** Registry'deki bütün controller action'ları callable; yedi satırın her biri için izinli ve yetkisiz test var; dış Docker/Drive servisleri mock; 500 veya sahte başarı yok.

- [ ] **BUG-02.08** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-03 — Yüksek — API controller'ları mevcut olmayan servis metotlarını çağırıyor

**Kanıt/eksiksiz işlem listesi:** Aşağıdaki çağrılar gerçek service sınıflarında yok. `MailProviderException::getMessage` gibi mirastan gelen metotlar taramadan elenmiştir.

| Yapılacak | `P/app/Http/Controllers/Api/V1/` konumu | Olmayan çağrı | Mevcut tasarıma bağlama yönü |
|---|---|---|---|
| [ ] BUG-03.01 | `P/app/Http/Controllers/Api/V1/DashboardController.php:50` | `DockerServiceManager::performAction` | start/stop/restart için kontrollü dispatch |
| [ ] BUG-03.02 | `P/app/Http/Controllers/Api/V1/DockerServiceController.php:24` | `create` | validate → model oluştur → deploy job; web store akışı |
| [ ] BUG-03.03 | `P/app/Http/Controllers/Api/V1/DockerServiceController.php:37` | `update` | UpdateDockerServiceRequest + web update/deploy akışı |
| [ ] BUG-03.04 | `P/app/Http/Controllers/Api/V1/DockerServiceController.php:44` | `delete` | gerçek `remove` + model/config cleanup sözleşmesi |
| [ ] BUG-03.05 | `P/app/Http/Controllers/Api/V1/DockerServiceController.php:53` | `performAction` | `start/stop/restart/deploy`; pull davranışını açık tanımla |
| [ ] BUG-03.06 | `P/app/Http/Controllers/Api/V1/DomainLogController.php:25` | `DomainRequestLogService::getEntries` | `getDomainEntries(Domain,array)` filtre formatı |
| [ ] BUG-03.07 | `P/app/Http/Controllers/Api/V1/DomainLogController.php:38` | `getNewEntries` | cursor/since'ı gerçek log servisine veya stream yoluna çevir |
| [ ] BUG-03.08 | `P/app/Http/Controllers/Api/V1/ModSecurityController.php:53` | `WafLogService::getLogsForDomain` | `getDomainEntries(Domain,array)` |
| [ ] BUG-03.09 | `P/app/Http/Controllers/Api/V1/SslController.php:29` | `requestLetsEncrypt` | web `storeLetsEncrypt` / async SSL job |
| [ ] BUG-03.10 | `P/app/Http/Controllers/Api/V1/SslController.php:45` | `generateSelfSigned` | web `storeSelfSigned` iş akışı |
| [ ] BUG-03.11 | `P/app/Http/Controllers/Api/V1/SslController.php:80` | `validatePrivateKey` | web key parse/match validation sözleşmesi |
| [ ] BUG-03.12 | `P/app/Http/Controllers/Api/V1/SslController.php:95` | `uploadCertificate` | `storeUploadedCert` doğru argümanları/validation |
| [ ] BUG-03.13 | `P/app/Http/Controllers/Api/V1/SslController.php:127` | `completeCsr` | `completeCsrWithCertificate` + domain ve doğrulanmış alanlar |
| [ ] BUG-03.14 | `P/app/Http/Controllers/Api/V1/SslController.php:143` | `exportAsZip` | web export contract'ı, stream/binary response ve ayrı izin |
| [ ] BUG-03.15 | `P/app/Http/Controllers/Api/V1/SslController.php:161` | `importCertificate` | web `importPem`, PEM ayrıştırma/key eşleştirme |
| [ ] BUG-03.16 | `P/app/Http/Controllers/Api/V1/SslController.php:171` | `delete` | active-cert koruması + DB/disk cleanup |
| [ ] BUG-03.17 | `P/app/Http/Controllers/Api/V1/SslController.php:180` | `cancelPending` | gerçek job/domain operation cancel protokolü |

**Referans servisler:** `P/app/Services/DockerServiceManager.php:20–127`, `P/app/Services/DomainRequestLogService.php:26`, `P/app/Services/WafLogService.php:31`, `P/app/Services/SslCertificateService.php:299,335,571,598`.

**Düzeltme:** Metot isimlerini körlemesine değiştirip argüman uyuşmazlığı yaratmayın. Her satırda web iş mantığını, transaction/job/cleanup/güvenlik sırasını ortak action üzerinden paylaşın. SEC-04/09 önce çözülmelidir; çalışan hale gelmesi yetkisiz/root operasyonu etkinleştirmesin.

**Kabul:** Tablodaki **17 çağrı noktasının her biri** için gerçek service API'sine uyum testi; başarısız dış işlemde kontrollü hata ve tutarlı veri; normal request'te undefined-method 500 yok.

- [ ] **BUG-03.18** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-04 — Yüksek — SSL ve yedek indirme API'lerinin argüman/alan sözleşmesi yanlış

**Kanıt ve ayrı tamamlanacak alt görevler:**
- [ ] **BUG-04.01** `P/app/Http/Controllers/Api/V1/SslController.php:61`, `generateCsr($domain,$validated)` çağırıyor; `P/app/Services/SslCertificateService.php:335–341` domain + commonName string + keyType string + csrFields + sanDomains bekliyor. Web controller'daki aynı alan ayrıştırmasını ortak servisten kullanın. Geçerli CSR formu ile başarılı üretim ve SAN/key-type doğrulama testi ekleyin.
- [ ] **BUG-04.02** `P/app/Http/Controllers/Api/V1/SslController.php:70`, `csr_content` okuyor; `P/app/Models/SslCertificate.php:42` gerçek alan `csr_pem`. Dolu fixture CSR indirildiğinde byte eşitliği, MIME ve güvenli dosya adı doğrulanmalı; boş 200 dönmemeli.
- [ ] **BUG-04.03** `P/app/Http/Controllers/Api/V1/BackupController.php:59–67`, `downloadFile` cevabında `content` base64 bekliyor. `P/app/Services/GoogleDriveService.php:402–416` `name/mimeType/size/stream` döndürüyor; mevcut kod boş gövdeli indirme üretir. Web `BackupController::driveDownload` benzeri `StreamedResponse` kullanın; exception ve client disconnect yönetimini koruyun. Sahte stream'in byte/hash eşitliği ve büyük dosyada sabit bellek testi yapın.

**Kabul:** Üç alt görev ayrı test sonucu taşır; undefined/yanlış alan, TypeError/ArgumentCountError veya boş dosya cevabı kalmaz. Secret export yetkileri SEC-04 ile doğrulanır.

- [ ] **BUG-04.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-05 — Yüksek — PHP ayarı kaydı ile çalışan PHP/FPM durumu birbirinden kopuyor

**Kanıt:** API `P/app/Http/Controllers/Api/V1/PhpSettingsController.php:19–25` yalnız PhpSetting kaydı güncelliyor; domain `php_version_id`, config render veya reload yok. Web `P/app/Http/Controllers/PhpSettingsController.php:39–73` versiyon değişiminde servis çağırıyor, ayarları yazıp config üretse de aynı versiyondaki ayar değişiminden sonra FPM reload çağrısı yok. `PhpFpmConfigRenderer::writePhpFpmConfig` yalnız dosya yazar.

**Düzeltme:**
- [ ] **BUG-05.01** Web/API için ortak validate → güvenli candidate config → syntax check → doğru FPM servisine graceful reload → durum onayı akışı kullanın.
- [ ] **BUG-05.02** `php_version_id`'yi PhpSetting'e mass assignment yapmak yerine Domain üzerinden yönetin; disabled/uyumsuz PHP sürümünü reddedin.
- [ ] **BUG-05.03** FPM dışındaki embedded PHP için bu ayarların hangi kanaldan uygulanacağını açıkça destekleyin veya desteklenmeyen alanı reddedin.
- [ ] **BUG-05.04** Reload hatasında DB/disk/çalışan durumu tutarlı tutun; eski aktif config'i koruyun.

**Kabul:** Aynı sürümde memory_limit değişikliği ve sürüm değiştirme hem API hem web'den çalışan fixture PHP'de gözlenir; failed validation/reload değişikliği uygulanmış göstermez.

- [ ] **BUG-05.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-06 — Yüksek — API ayar alanları model şemasıyla uyuşmuyor; başarı cevabı etkisiz kalıyor

**Kanıt:** `P/app/Http/Controllers/Api/V1/SettingsController.php:25–169` ile ilgili model `$fillable` alanları uyuşmuyor. Modelde olmayan alanlar ayara göre sessiz atılır veya exception üretir; başarılı response, ayarın uygulanmasını kanıtlamaz.

| Yapılacak | API alanı/yolu | Gerçek alan/ilişki ve onarım |
|---|---|---|
| [ ] BUG-06.01 | `updateDns`: `soa_email`, `soa_minimum` | `DnsSetting`: `soa_admin_email`, `soa_minimum_ttl`; web ile aynı validation |
| [ ] BUG-06.02 | `updateAcme`: `directory_url`, `eab_kid`, `eab_hmac_key` | `AcmeSetting`: `server_url` vb.; EAB desteklenmiyorsa sessiz kabul etme, ayrı account modeline doğru bağla |
| [ ] BUG-06.03 | `store/updateDnsTemplate`: `records` | `DnsTemplate::records()` ayrı HasMany; kayıtları transaction ile create/update et; show'da ilişkiyi yükle |
| [ ] BUG-06.04 | `setDefaultDnsTemplate` | tümünü false sonra tekini true atomik olmalı; paralel istekte tek default |
| [ ] BUG-06.05 | `updateAntiBot`: `anti_bot_enabled/challenge_threshold` | `SecuritySetting`: captcha_provider, provider key'leri, honeypot_enabled; web FormRequest ile hizala |
| [ ] BUG-06.06 | `loginIpFilter/updateLoginIpFilterMode` | `login_ip_filter_mode` yerine `ip_filter_mode`; `disabled` yerine gerçek `off` enum karşılığı |
| [ ] BUG-06.07 | `storeLoginIpRule`: `ip_cidr/description` | `LoginIpRule`: `ip_address/note/created_by`; IPv4/IPv6/CIDR gerçek parser doğrulaması |
| [ ] BUG-06.08 | `updateAlerts`: `cpu/ram/disk_threshold` | `AlertSetting`: her metrik için `_warning` ve `_critical`; sıralama ve aralık doğrulaması |

**Düzeltme:** Yukarıdaki sekiz satırı ayrı kapatın. Response'ları read-back edilen canonical alanlardan üretin; unsupported alanlara 422 verin. SEC-04 yetkisini uygulayın. SecuritySetting'in encrypted cast alanları ham serialization ile plaintext dönebilir; GET antiBot'ta secret yerine yalnız `configured` bayrakları verin.

**Kabul:** Her ayar için API write → DB read → web read → ilgili çalışma davranışı eşleşir; geçersiz alanlar sessiz başarılı olmaz; güvenlik ayarı GET cevabında secret sentinel yoktur.

- [ ] **BUG-06.09** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-07 — Orta — Handshake wildcard webhook hiçbir normal olaya abone olmuyor

**Kanıt:** `P/app/Http/Controllers/Api/V1/HandshakeController.php:33` `events=['*']`; `P/app/Services/WebhookService.php:12–16` yalnız `whereJsonContains('events',$event)` arıyor. JSON içindeki literal `*`, `domain.created` ile eşleşmez.

**Düzeltme/kabul:**
- [ ] **BUG-07.01** SEC-07 yetkisini önce kapatın. `active AND (contains exact event OR contains '*')` gruplamasını veya wildcard'ı kayıt anında kontrollü olay listesine genişletmeyi uygulayın.
- [ ] **BUG-07.02** Wildcard endpoint her desteklenen olayda bir kez, exact endpoint yalnız kendi olayında bir kez, inactive endpoint sıfır kez job üretmeli. Yeni olay eklendiğinde wildcard davranışı da test edilmeli.

- [ ] **BUG-07.03** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-08 — Orta — Webhook retry ayarları kullanılmıyor; delivery kimliği de retry'da değişiyor

**Kanıt:** `P/app/Jobs/SendWebhookJob.php:19–21,30,57–64,69–77`: üç deneme/backoff tanımlı, fakat HTTP hatası ve exception'da `$this->fail(...)` çağrılıyor. Bu iş kalıcı failed işaretlenir; normal retry mekanizmasına exception ile dönmez. `deliveryId` ve timestamp her `handle()` içinde yeniden üretiliyor.

**Düzeltme:**
- [ ] **BUG-08.01** 429/5xx/connection timeout gibi retry edilebilir hatalarda exception/release yolunu; kalıcı 4xx için final failure yolunu ayırın. Retry-After ve sınırlı backoff uygulayın.
- [ ] **BUG-08.02** Delivery ID ve imzalanacak payload/timestamp'ı dispatch anında sabitleyin; retry aynı olay kimliğini taşısın. HMAC için imzalanan byte dizisiyle gönderilen gövde birebir aynı olsun.
- [ ] **BUG-08.03** Endpoint inactive/silinmişse kuyruktaki işin davranışını belirleyin; başarısızlığı yalnız son denemede kalıcı kaydedin.

**Kabul:** Mock HTTP 500→200 ve timeout→200 iki denemede başarılı; 400 politikaya göre kalıcı; retry'lar aynı delivery ID/gövdeyi taşıyor; imza ham gövde üzerinden doğrulanıyor. [Laravel queue belgesi](https://laravel.com/framework/docs/13.x/queues) fail/retry yaşam döngüsüne başvurudur.

- [ ] **BUG-08.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-09 — Orta — Tek webhook'u test etme işlemi bütün abonelere test verisi gönderiyor

**Kanıt:** `P/app/Http/Controllers/Settings/WebhookWebController.php:99–105` seçilen endpoint'in ilk olayını `WebhookService::dispatch` ile genel yayınlıyor; servis aynı olaya abone tüm aktif endpoint'leri seçiyor.

**Düzeltme/kabul:**
- [ ] **BUG-09.01** Test action'ı yalnız seçilen endpoint'e özel `SendWebhookJob` oluştursun; gerçek global event fan-out'u kullanmasın. Test flag'i/imzası ve inactive endpoint test politikasını belirleyin.
- [ ] **BUG-09.02** Aynı event'e abone iki endpoint fixture'ında A'yı test ederken yalnız A job'ı üretilsin; B'ye hiçbir istek gitmesin. Gerçek event dispatch'in fan-out davranışı korunsun.

- [ ] **BUG-09.03** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-10 — Yüksek — Silinen FTP kullanıcısı users.env içinde korunup yeniden oluşturuluyor

**Kanıt:** `P/app/Services/FtpUserService.php:91–98` önce DB kaydını siliyor, sonra `syncUsersEnv` çağırıyor. `:237–245` DB'de bulunmayan mevcut dosya kayıtlarını unmanaged diye koruyor. Böylece az önce silinen kullanıcı satırı kalıyor. FrankenPHP/PHP code server entrypoint'leri users.env'den OS kullanıcılarını yeniden oluşturuyor.

**Etki:** Panelden silinen hesabın OS kimliği bir sonraki başlangıçta geri gelebilir; eski path/UID kaydı yaşamaya devam eder. Bu tek başına eski parolayla FTP login'in kesin çalıştığı anlamına gelmez: ProFTPD SQL auth ve dummy password ayrımı korunmalıdır.

**Düzeltme:**
- [ ] **BUG-10.01** Silinecek yönetilen username/UID'yi sync işlemine açık tombstone olarak verin veya kullanıcıların kaynağını metadata ile ayırın; DB'de yok diye otomatik unmanaged yapmayın.
- [ ] **BUG-10.02** DB + dosya değişimini kilitleyin ve atomik dosya değişimi yapın. Unmanaged gerçek hesapları koruyun; rename ve UID yeniden kullanımını birlikte ele alın.
- [ ] **BUG-10.03** Eski orphan kayıtları migration'da gerçek yönetilen hesap geçmişiyle temizleyin; canlı sahipsiz dosyaları silmeyin.

**Kabul:** Silme sonrası hem DB hem users.env kaydı yok; entrypoint simülasyonu hesabı yeniden yaratmıyor; unmanaged hesap kalıyor; rename/parallel create/delete UID çakışması üretmiyor.

- [ ] **BUG-10.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-11 — Kritik — MySQL upgrade yedeği çalışan data dizininin tutarsız fiziksel kopyası

**Kanıt:** `update-agent/app/routers/mysql.py:141–176` prepare sırasında MySQL durdurulmadan `cp_data_dir` çağrılıyor. `update-agent/app/services/mysql_ops.py:149–176` düz dosya kopyası ve yalnız toplam byte büyüklüğü kıyası yapıyor. MySQL stop ancak apply `update-agent/app/routers/mysql.py:261` aşamasında. Yazılan InnoDB data/redo dosyalarının farklı zamanlara ait kopyası bu yöntemle doğrulanamaz.

**Etki:** “Backup complete” durumu geri yüklenebilir veritabanı kanıtı değildir. Özellikle yoğun yazma altında upgrade başarısızlığında kullanılacak tek kopya açılmayabilir veya son işlemleri eksik taşıyabilir. Prepare ile apply arasındaki yeni veriler için de geri dönüş noktası belirsizdir.

**Düzeltme:**
- [ ] **BUG-11.01** Desteklenen tutarlı fiziksel backup yöntemi veya temiz shutdown/snapshot protokolü seçin. Online dosya kopyasını backup başarı ölçütü saymayın.
- [ ] **BUG-11.02** Snapshot/backup ID, kaynak MySQL sürümü, zaman/LSN veya uygun tutarlılık metadatası, doğrulama ve restore test sonucu kaydedin.
- [ ] **BUG-11.03** Prepare/apply arasında backup'ın yaşını ve değişen veriyi denetleyin; upgrade öncesi gerekli son tutarlı kopyayı alın. Eski backup'ı yeni doğrulanmadan kaldırmayın.
- [ ] **BUG-11.04** Geçici, üretimden tamamen ayrı MySQL instance'ında restore + veri tutarlılığı kontrolü geçmeden apply izni vermeyin.

**Kabul:** Eşzamanlı fixture yazmaları altında alınan yedek izole restore olur; kayıt/sentinel invariant'ları korunur; bozuk veya sadece aynı boyuttaki kopya reddedilir. Yöntem [MySQL InnoDB backup belgesi](https://dev.mysql.com/doc/refman/8.4/en/innodb-backup.html) ile uyumlu olmalıdır.

- [ ] **BUG-11.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-12 — Kritik — MySQL recovery mevcut veriyi doğrulanmış alternatif olmadan kaldırıyor

**Kanıt:** `update-agent/app/routers/mysql.py:389–407` recovery önce mevcut data'yı kaldıran komutu çalıştırıyor, **sonra** backup dizini var mı diye kontrol ediyor. Explicit rollback `:459–477` de yalnız dizin varlığını kontrol edip güncel veri üzerine kopya kuruyor. Stop/remove dönüşleri bazı noktalarda kontrol edilmiyor. BUG-11 backup tutarlılık sorunu bu etkiyi büyütür.

**Düzeltme:**
- [ ] **BUG-12.01** Restore kaynağını, metadata/hash/tutarlılığını, doğru kaynak sürümünü ve boş alanı önce doğrulayın. Backup yok/bozuksa canlı veri dizinini değiştirmeden durun.
- [ ] **BUG-12.02** Mevcut veri dizinini kurtarma için koruyun; staged restore + doğrulama + kontrollü geçiş tasarlayın. Bu rapor hiçbir üretim veri silme komutu çalıştırmayı önermiyor.
- [ ] **BUG-12.03** MySQL'in gerçekten durduğunu doğrulayın; stop/remove/restore adımlarının her hatasını sonlandırıcı ele alın. Sürümler arası data directory downgrade güvenliğini varsaymayın.
- [ ] **BUG-12.04** Geri dönüş sonrası authenticated DB sorgusu, sürüm ve beklenen veri kontrolleri yapın; `running`/ping tek başına başarı olmasın.

**Kabul:** Eksik/bozuk backup, disk dolması, stop hatası, kopya yarıda kesilmesi ve eski sürüm açılmaması mock/fault testlerinde orijinal veriyi korur. Mevcut veriyle test yapılmaz.

- [ ] **BUG-12.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-13 — Yüksek — Laravel backup kısmi/tam başarısızlığı “completed” sayıp eski yedekleri temizleyebiliyor

**Kanıt:** `P/app/Jobs/BackupUploadJob.php:123–135` MySQL listeleme hatası loglanıp boş listeye düşüyor; `:266–273,341–348,361–367,462–470` archive/dump hataları `continue` ile geçiliyor; Postgres erişim hatası da atlanıyor. `:175–193` yine retention cleanup ve `status=completed` çalışıyor.

**Etki:** Sıfır DB yedeği veya eksik sitelerle başarı bildirimi; yeni sağlıklı yedek olmadan eski kurtarma noktalarının silinmesi. Yedekleme ürünün kritik işlevi olduğundan yalnız warning log yeterli değil.

**Düzeltme:**
- [ ] **BUG-13.01** Beklenen kaynakların manifest'ini önce oluşturun; her kaynak için success/failed/skipped-with-reason, boyut, hash ve Drive file ID kaydedin.
- [ ] **BUG-13.02** Config'de istenen DB servisine ulaşılamaması ile bilerek kapsam dışı servisi ayırın. Zorunlu kaynak hatası run'ı failed/partial yapsın.
- [ ] **BUG-13.03** Retention yalnız yeni doğrulanmış kurtarma noktası ve tanımlı asgari sağlıklı kopya sayısı korunarak çalışsın; partial/empty koşulunda mevcut sağlıklı yedekleri temizlemeyin.
- [ ] **BUG-13.04** UI/API/bildirimlerde gerçek partial/failed sonucu ve hangi kaynakların eksik olduğunu gösterin.

**Kabul:** DB list failure, tek site tar failure, tek DB dump failure, boş upload ve Postgres erişim hatası ayrı testler; hiçbiri koşulsuz completed veya eski sağlıklı yedek cleanup üretmez; başarılı manifest restore testiyle doğrulanır.

- [ ] **BUG-13.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-14 — Yüksek — Backup cancel/restart ve overlap kilidi işleri kaybediyor

**Kanıt:** `P/app/Jobs/BackupUploadJob.php:70` `WithoutOverlapping(...)->dontRelease()->expireAfter(86400)`; kilitli yeni job sessizce kuyruktan düşebilir, onun `BackupRun` kaydı running kalır. `P/app/Http/Controllers/BackupController.php:285–309` cancel, worker'ı durdurmadan o güne ait ortak temp dizinini temizliyor; job gerçek path'i `.../tarih/runId`. `:311–352` restart eski işe yalnız DB cancel yazıp yeni işi hemen dispatch ediyor. Eski işin tar/upload aşaması sürerken yeni iş kilide çarpabilir.

**Düzeltme:**
- [ ] **BUG-14.01** Run durumlarını queued/running/cancel_requested/cancelled/partial/completed/failed olarak açık state machine ile yönetin; aktif job'ı başka request temizlemesin.
- [ ] **BUG-14.02** Cancel isteğini worker kontrollü sınırda görsün; subprocess/upload iptal ve cleanup yalnız ilgili run'ın temp dizininde yapılsın.
- [ ] **BUG-14.03** Restart, eski iş gerçekten sonlanıp kilidi bırakmadan yeni işi çalıştırmasın. Overlap durumunda job'ı silmek yerine kuyruğa kontrollü erteleyin veya yeni isteği 409 ile reddedin.
- [ ] **BUG-14.04** Worker crash, kilit TTL ve stale running kayıtları için reconciliation ekleyin; sonsuz timeout ile 24 saat kilit arasındaki uyumsuzluğu giderin.

**Kabul:** İki eşzamanlı run, aktif upload sırasında cancel, restart, gece yarısı geçişi ve worker crash testlerinde kaybolan job/stale running yok; başka run dizini etkilenmiyor; en fazla bir aktif backup var.

- [ ] **BUG-14.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-15 — Yüksek — Update-agent Compose bind kaynaklarını host'ta yanlış `/project` yoluna çözüyor

**Kanıt:** `docker-compose.yaml:623,627`, host proje dizinini ajan içinde `/project` olarak mount ediyor. `update-agent/app/services/panel_ops.py:157–159,188–206` bu yoldaki compose dosyasını `-f /project/docker-compose.yaml` ile çalıştırıyor. Relative bind kaynakları `/project/...` olarak hesaplanır; Docker daemon bunları kendi host dosya sisteminde çözer. `COMPOSE_PROJECT_NAME` düzeltmesi sadece proje etiketidir, mount yolunu düzeltmez.

**Önkoşul/etki:** Host'taki gerçek kurulum dizini `/project` değilse yeniden oluşturulan servisler yanlış/boş veri kaynaklarına bağlanabilir veya mount hatası verir. Salt `docker exec` yolları bundan etkilenmez; risk bind mount'ları yeniden kuran `compose up/recreate` aşamasındadır. Build context'inin ajan içinden okunabilmesi ayrıca değerlendirilir; tek başına image build bu mount hatasını kanıtlamaz.

**Düzeltme:**
- [ ] **BUG-15.01** Ajan içi proje yolu ile host bind kökünü açık ayrı konfigürasyon yapın; compose'a verilen absolute kaynakların daemon host'taki gerçek dizinlere karşılık geldiğini doğrulayın.
- [ ] **BUG-15.02** Tercihen proje ajan içine de aynı absolute host path'te mount edilsin veya kontrollü resolved compose üretiminde yalnız bind source'lar doğru host köküne çevrilsin. Sadece project-name ayarlamayın.
- [ ] **BUG-15.03** Çalışan container mount metadata'sı ile hedef compose mount source'larını preflight'ta karşılaştırın; data mount farkında güncellemeyi durdurun.

**Kabul:** `/opt/...` gibi `/project` olmayan temiz fixture kurulumunda update öncesi/sonrası tüm veri bind source'ları aynı; boş yeni data dizini oluşmuyor. Host/daemon path ayrımı [Docker bind mount belgesinde](https://docs.docker.com/engine/storage/bind-mounts/) açıklanır.

- [ ] **BUG-15.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-16 — Yüksek — Güncelleme kritik adım hatalarını başarı olarak sonlandırıyor ve hedef sürümü sabitlemiyor

**Kanıt:** `update-agent/app/routers/panel.py:71` branch'e `git pull --ff-only`; belirlenen release commit'i checkout edilmiyor. Aynı dosyada optimize, compose service listing/up, running health loop ve maintenance-off hataları warning seviyesinde kalıp sonunda `TaskStatus.COMPLETED` olabiliyor. Uygulama kodu canlı mount'ta değiştirilip dependency/build/migration sonra yapılıyor; başarısız adım için eski release'a atomik dönüş yok.

**Düzeltme:**
- [ ] **BUG-16.01** Kullanıcının onayladığı hedef release/tag/commit'i doğrulayıp sabitleyin; ilerleme kaydına gerçek from/to commit ve image digestlerini yazın.
- [ ] **BUG-16.02** Candidate release'da dependency/build/config kontrollerini tamamlayın; canlıya geçişi kontrollü yapın. Migration uyumluluğu ve rollback sınırını açıkça belirleyin.
- [ ] **BUG-16.03** Compose config/up, zorunlu optimize, app HTTP health, queue ve maintenance-off başarısızlığında failed/partial sonucu verin; sadece container running kontrolünü yeterli saymayın.
- [ ] **BUG-16.04** `scripts/upgrade/` gibi veri/secret/config geçişlerini sürüm sıralı, idempotent migration planına bağlayın; yeni kodun gerektirdiği ortamın oluştuğunu doğrulayın.
- [ ] **BUG-16.05** Self update-agent değişimi için ayrıca doğrulanabilir tamamlanma adımı ve yeniden başlatma sonrası reconciliation uygulayın.

**Kabul:** Her kritik adımda hata enjeksiyonu yanlış completed üretmez; panel sağlıklı değilken bakım modu bilinçsiz kaldırılmaz; hedef commit çalışır; eski kurulumdan geçiş testi ve güvenli rollback planı vardır.

- [ ] **BUG-16.06** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-17 — Yüksek — Update/prepare/apply/rollback/cleanup arasında ortak kilit ve kalıcı durum yok

**Kanıt:** `update-agent/app/routers/panel.py` endpoint'i her istekte background task başlatıyor. `update-agent/app/routers/mysql.py:534–608` prepare/apply/rollback/cleanup aynı staging/backup/data dizinlerini kilitsiz kullanıyor. `update-agent/app/services/task_manager.py:43–79` işler yalnız memory'de; restart sonrası durum kaybolur, kapasite aşımında aktif görev de evict edilebilir.

**Etki:** İki istek aynı anda update başlatabilir; cleanup aktif restore'un kaynağını kaldırabilir. Ajan restart'ında panel işin kaybolduğunu başarısızlık sanırken gerçek sistem durumu belirsiz kalır. SEC-04 nedeniyle bu uçların erişimi ayrıca kritiktir.

**Düzeltme:**
- [ ] **BUG-17.01** Proje/MySQL mutation'ları için süreçler ve restart'lar arasında geçerli ortak lock + operation ID + kalıcı state kaydı oluşturun.
- [ ] **BUG-17.02** Durum geçişlerini izinli sırayla sınırlandırın; prepare devam ederken apply, apply/rollback devam ederken cleanup ve ikinci update 409 almalı.
- [ ] **BUG-17.03** Staging/backup dizinlerini operation ID'ye bağlayın; başka işlemin dosyalarına dokunmayın. Ajan açılışında yarım işleri sistem gerçeğiyle reconcile edin.
- [ ] **BUG-17.04** Task retention yalnız terminal durumdaki işleri temizlesin; aktif işi kapasite için silmeyin.

**Kabul:** Paralel prepare/apply/cleanup ve ajan restart testlerinde tek mutation, korunmuş kaynaklar ve yeniden okunabilir durum; UI polling bilinmeyen işi başarı/otomatik yıkıcı retry saymıyor.

- [ ] **BUG-17.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-18 — Orta — MySQL API prepare her zaman geçersiz `latest` sürümünü gönderiyor

**Kanıt:** `P/app/Http/Controllers/Api/V1/SystemUpdateController.php:25–29` `prepareMysqlUpgrade('latest')`; `update-agent/app/routers/mysql.py:43–55` yalnız `MAJOR.MINOR.PATCH` kabul ediyor. UI'nin seçilmiş somut sürüm akışı API'de yok.

**Düzeltme/kabul:**
- [ ] **BUG-18.01** API request'inde target_version alıp web ile aynı strict version + desteklenen upgrade yolu doğrulamasını kullanın. Latest gerekiyorsa önce gerçek sürüme resolve edin, kullanıcıya/operation kaydına sabitleyin.
- [ ] **BUG-18.02** Geçerli semver ajan mock'una aynen gider; `latest`, yanlış format ve desteklenmeyen major atlama 422/uygun hata döner. Gerçek upgrade başlatmadan test edin.

- [ ] **BUG-18.03** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-19 — Yüksek — Hatalı custom config reload'u bütün web container'ını durdurabilecek restart'a dönüşüyor

**Kanıt:** `P/app/Http/Controllers/DomainCustomConfController.php:34–57`, canlı dosyaya önce yazar ve `reloadCaddy()` bool sonucunu yok sayıp başarı döner. `P/app/Services/ReloadService.php:67–82`, reload parse/compile hatasında aynı disk config'i ile container restart yapar. Caddy'nin eski geçerli config'i koruyarak reddettiği bir aday, restart'tan sonra başlangıç hatasına dönüşebilir.

**Düzeltme:**
- [ ] **BUG-19.01** Candidate config'i ayrı konumda üretin; bütün import'lar ve WAF kurallarıyla native validate geçmeden canlı dosyaya taşımayın.
- [ ] **BUG-19.02** Global config mutation lock, atomic replace ve previous-version rollback kullanın. Parse/validation hatasında container restart'a fallback yapmayın.
- [ ] **BUG-19.03** Reload başarısızsa eski dosyayı geri koyun ve başarısızlığı HTTP/UI'ye yansıtın; generic network hatası ile invalid config'i ayırın.

**Kabul:** İzinli karakterlerden oluşan fakat syntactically invalid custom config testinde eski site çalışır, restart çağrısı yapılmaz, disk eski içerikte kalır ve kullanıcı hata alır. Meşru config eşzamanlı başka domain değişikliğini kaybetmez.

- [ ] **BUG-19.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-20 — Orta — Parola sıfırlama ve TOTP arayüz yolları eksik

**Kanıt/alt görevler:**
- [ ] **BUG-20.01** `P/routes/web.php:123` Laravel UI reset route'larını açık bırakıyor; `P/app/Http/Controllers/Auth/ForgotPasswordController` ve `P/app/Http/Controllers/Auth/ResetPasswordController` trait'leri `auth.passwords.email/reset` view'larını bekliyor. `resources/views` içinde bu view'lar yok. Inertia reset request/reset form sayfaları ve controller render'ları ekleyin; geçerli/geçersiz token ve mail gönderimi test edin.
- [ ] **BUG-20.02** `P/app/Http/Controllers/Auth/ResetPasswordController.php:28` reset sonrası `/home` yönlendirmesi var; home route `/`. Named route ile düzeltin; reset sonrası 404 olmamalı.
- [ ] **BUG-20.03** `P/resources/js/Pages/User/Security.vue:179–180` shared `auth.user.two_factor_secret/confirmed` bekliyor; `HandleInertiaRequests::share` bunları göndermiyor. Secret'ı göndermek yerine `two_factor_enabled/two_factor_confirmed` boolean props tanımlayın.
- [ ] **BUG-20.04** Aynı Vue dosyası `:234` `two-factor.qr-code` route'u çağırıyor; `FortifyServiceProvider::register` ignoreRoutes kullanıyor ve web'de QR route'u yok. Yetkili, yakın zamanda doğrulanmış enrollment için gereken QR/recovery uçlarını açıkça tanımlayın, secret'ları log/cache dışı tutun.

**Kabul:** Sayfa yenilendiğinde TOTP durumu doğru, enable→QR→confirm→disable tamamlanabilir; reset GET'leri 500 üretmez; yeni password ile giriş ve doğru home redirect çalışır. SEC-15 state düzeltmesi önce/beraber yapılır.

- [ ] **BUG-20.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-21 — Orta — DNS/ACME ayar permission adları izin kataloğunda yok

**Kanıt:** `P/routes/web.php:662,668,674,681`, `panel.dns-settings.manage`, `panel.acme-settings.manage`, `panel.dns-templates.manage` kullanıyor; bu adlar `P/config/panel-permissions.php` içinde bulunmuyor. Bu yüzden katalog/seed üzerinden yetki atanan delegasyon rollerine bu izinler normal akışta verilemez; mevcut DB'ye elle eklenmiş izinler varsa çalışma koşulu farklıdır.

**Düzeltme/kabul:**
- [ ] **BUG-21.01** Üç permission'ı canonical kataloğa, açıklamalara, role UI ve idempotent seed/migration'a ekleyin veya route'ları mevcut doğru permission adlarına taşıyın. Admin bypass'ına güvenmeyin.
- [ ] **BUG-21.02** Katalogdaki tüm route permission string'lerini statik olarak doğrulayın. Yeni kurulumda yetkili delege rolü sayfalara erişmeli; izinsiz rol 403 almalı; mevcut rol yetkileri kaybolmamalı.

- [ ] **BUG-21.03** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-22 — Orta — Bir yıl sonra bitecek sertifika “yakında doluyor” olarak işaretleniyor

**Kanıt:** `P/app/Models/SslCertificate.php:80–84`, gelecekteki `not_after->diffInDays(now()) <= 30` kontrolü var. Kurulu Carbon'da fark signed. İzole kontrol: 365 gün sonraki tarih için sonuç **-365**, koşul **true**. `SslRenewCommand` kendi doğru yöndeki farkını kullandığından bu bulgu tüm sertifikaların otomatik yenilendiği iddiası değildir; model/API/UI `is_expiring_soon` alanı yanlıştır.

**Düzeltme/kabul:**
- [ ] **BUG-22.01** Geleceğe kalan günü doğru yönde/signed farkla veya açık tarih aralığıyla hesaplayın; `0 ≤ remaining ≤ 30` kuralını uygulayın.
- [ ] **BUG-22.02** Zamanı dondurup expired, şimdi, 1/30/31/365 gün ve null expiry vakalarını test edin. `days_until_expiry` ile `is_expiring_soon` çelişmesin.

- [ ] **BUG-22.03** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-23 — Yüksek — API PHP sürümü açma/kapatma işlemi çalışma zamanını değiştirmiyor

**Kanıt:** `P/app/Http/Controllers/Api/V1/PhpVersionController.php:19–24`, enjekte edilen `PhpFpmSupervisorService` kullanılmadan yalnız `is_enabled` tersine çevriliyor. `:35–49` FrankenPHP ini kaydı da yalnız dosya yazıyor; çalışan PHP sürecine uygulama yok. Aynı controller'ın `updatePhpIni` metodu ise FPM restart çağırıyor; bu davranışlar tutarsız.

**Etki:** API başarı dönerken kapatıldığı bildirilen sürüm çalışmaya devam edebilir; açıldığı bildirilen sürüm hizmet vermez. Yeni güvenlik ini ayarı sonraki restart'a kadar etkinleşmez. Bu, domain bazındaki BUG-05'ten ayrı global sürüm yaşam döngüsü sorunudur.

**Düzeltme/kabul:**
- [ ] **BUG-23.01** Web/API için ortak, yetkili bir PHP sürüm yönetim servisi kullanın. Bağımlı domainleri denetleyin; kullanımdaki sürümü kapatma davranışını açıkça belirleyin.
- [ ] **BUG-23.02** İstenen durum → native config doğrulama → supervisor/runtime uygulama → gerçek durum kontrolü sırasını kurun. Hata halinde DB'yi yanlış enabled/disabled durumda bırakmayın.
- [ ] **BUG-23.03** Ini dosyasını atomik yazıp ilgili runtime'a kontrollü uygulayın; `file_put_contents` false sonucunu exception olmadığı durumda da kontrol edin. Syntax hatasında eski config ve hizmet korunmalı.
- [ ] **BUG-23.04** API aç/kapat testinde doğru servis çağrısı, başarısız uygulamada hata ve geri dönüş; ini testinde etkin runtime değerinin değiştiği doğrulansın. SEC-04 ve BUG-19 ile birlikte ele alın.

- [ ] **BUG-23.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-24 — Orta — Cron işi worker zaman aşımıyla yarıda kalıp logu `running` bırakabiliyor

**Kanıt:** `P/app/Jobs/ExecuteDomainCronJob.php:14–20` job timeout/failed callback tanımlamıyor; `:74–79` Docker exec için 300 saniye bekleyebiliyor. `alpha-panel/supervisor/AlphaPanel-queue.conf:3` özel worker timeout vermiyor; kurulu Laravel `P/vendor/laravel/framework/src/Illuminate/Queue/WorkerOptions.php:107–111` varsayılanı 60 saniye. Log önce `running` oluşturuluyor (`:27–31`); son durumu yalnız handle içindeki normal sonuç/catch güncelliyor (`:88–104`). Worker'ın timeout nedeniyle sonlandırılması normal catch yolu değildir. Handle başında artık disabled/silinmiş domain durumuna ve aynı cronun eşzamanlı çalışmasına yönelik kontrol de yok.

**Düzeltme/kabul:**
- [ ] **BUG-24.01** Cron süre sınırını ürün kuralı yapın; remote process deadline < job timeout < queue retry_after sırasını, worker stopwaitsecs ile birlikte doğrulayın. Sadece HTTP bağlantısını kapatmanın container içindeki süreci bitirdiğini varsaymayın.
- [ ] **BUG-24.02** Çalıştırmadan hemen önce cron/domain enabled durumu ve execution context'i yeniden kontrol edin; aynı cron için overlap politikasını açıkça uygulayın.
- [ ] **BUG-24.03** Execution ID ve terminal durum kaydı ekleyin. Timeout/worker ölümü/remote bağlantı kaybında failed callback ve periyodik reconciliation, eski `running` kayıtlarını doğru şekilde tamamlasın; remote süreci kontrollü iptal edebilsin.
- [ ] **BUG-24.04** 60–300 saniye aralığını simüle eden fake executor, worker timeout, kuyrukta beklerken kapatılmış cron ve overlap testleri çalışsın. Üretimde uzun gerçek komut çalıştırmayın.

- [ ] **BUG-24.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-25 — Orta — Google Drive listeleri ve retention yalnız ilk sayfayı işliyor

**Kanıt:** `P/app/Services/GoogleDriveService.php:200–211` folder listesinde 100; `:380–394` browser listesinde 200; `:435–469` dosya/klasör retention'ında 1000 kayıt sınırı var. Hiçbirinde `pageToken` iterasyonu yok; `fields` içinde `nextPageToken` da istenmiyor. Bu limitlerden fazla kayıt varsa kullanıcı dosyaları göremez ve eski yedeklerin bir bölümü retention dışında kalır. Google ayrıca sonuç sayfasının pageSize'dan kısa olabileceğini belirtir; bitiş ölçütü yalnız kayıt sayısı olamaz. [Drive files.list sözleşmesi](https://developers.google.com/workspace/drive/api/reference/rest/v3/files/list)

**Düzeltme/kabul:**
- [ ] **BUG-25.01** UI/API listelerine opaque next cursor ekleyin; SDK request fields'e `nextPageToken` katın. Eski response formatını uyumluluk planıyla güncelleyin.
- [ ] **BUG-25.02** Retention bütün sayfaları sınırlı batch'lerle tarasın; silme sırasında pagination tutarlılığını koruyun. Yalnız uygulamanın sahip olduğu, manifesti doğrulanmış backup nesneleri aday olsun; BUG-13 başarısız koşulunda retention çalışmasın.
- [ ] **BUG-25.03** Fake Drive ile 101/201/1001+ kayıt, boş fakat nextToken içeren sayfa, ikinci sayfa hatası ve retry testleri yapın. Eksik liste başarıyla tamamlanmış gibi sunulmasın; doğru eski yedeklerin tamamı işlenirken yeni/yabancı dosyalar korunsun.

- [ ] **BUG-25.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-26 — Orta — “Her iki haftada bir” ve gün aralıkları takvim sınırında yanlış

**Kanıt:** `P/app/Models/BackupSetting.php:52–63`, `every_2_weeks` seçimini ayın 1 ve 15'ine çeviriyor. 15'inden sonraki ayın 1'ine aralık 14 gün garantisi vermez; örneğin 31 günlük ayda 17 gündür. `every_2_days`/`every_3_days` cron day-of-month step'leri de ay başında sıfırlanır ve sabit gün aralığı sağlamaz.

**Düzeltme/kabul:**
- [ ] **BUG-26.01** Üründeki seçenek gerçekten süre aralığıysa kalıcı anchor/next_run_at üzerinden takvim günü aralığını hesaplayın; scheduler tick'inde due olan işi atomik claim edin. “Ayın 1 ve 15'i” isteniyorsa adı, API enum açıklaması ve UI metni buna göre değişsin; mevcut seçimleri sessizce başka semantiğe taşımayın.
- [ ] **BUG-26.02** Saat dilimi, DST, kaçırılan tetikleme ve önceki iş halen çalışıyorsa davranışı tanımlayın; BUG-14 ile tutarlı olsun.
- [ ] **BUG-26.03** 28/29/30/31 günlük ay geçişleri ve yıl sonu için gelecek tetiklemeleri dondurulmuş zamanla doğrulayın.

- [ ] **BUG-26.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-27 — Yüksek — MySQL config kaydı Linux dosya izinlerini değiştirip ayarı okunamaz bırakıyor

**Kanıt:** `update-agent/app/services/mysql_config_ops.py:49–67`, `NamedTemporaryFile` oluşturup `os.replace` ile gerçek `.cnf` dosyasının üzerine taşıyor; eski mode/owner veya hedef MySQL kullanıcısı için izin ayarlamıyor. `update-agent/Dockerfile` root varsayılanını değiştirmiyor; Compose'ta agent için user override yok. POSIX temporary file yalnız oluşturucu tarafından okunup yazılabilir (genellikle 0600). `docker-compose.yaml:295` bu dosyaları MySQL container'ına mount ediyor; mysqld'nin düşük yetkili mysql kullanıcısı bu root dosyasını okuyamayabilir. Linux deployment için kaynak çıkarımıdır; Windows dosya moduyla üretim davranışı test edilmedi. [Python tempfile izin sözleşmesi](https://docs.python.org/3/library/tempfile.html#tempfile.mkstemp)

**Düzeltme/kabul:**
- [ ] **BUG-27.01** Güvenilir mevcut dosyanın izin/sahibini atomik değiştirme öncesi taşıyın veya güvenli root-owner + MySQL'e read sağlayan açık mode/group politikası uygulayın. World-writable yapmayın; dosya içeriğinde secret varsa gereksiz dünya okuması vermeyin.
- [ ] **BUG-27.02** Symlink/path sınırlarını koruyun; temp dosyayı aynı filesystem'de validate → izin ayarı → fsync → replace sırasıyla yayınlayın. Dosya ve dizin dayanıklılığını gerektiği ölçüde ele alın.
- [ ] **BUG-27.03** Restart öncesi gerçek MySQL UID'si ile config okunabilirliği ve native config doğrulamasını kontrol edin; başarısızsa eski dosya/hizmet korunsun. Sadece compose restart exit code'unu hizmet sağlığı saymayın.
- [ ] **BUG-27.04** Linux izole fixture'da başlangıç owner/mode, write sonrası owner/mode ve MySQL UID read kontrolü; geçerli/geçersiz config senaryoları geçsin. Gerçek MySQL data dizininde test yapmayın.

- [ ] **BUG-27.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-28 — Yüksek — MySQL upgrade yolu yalnız major sayısına bakıyor; zorunlu LTS basamağı atlanabiliyor

**Kanıt:** `update-agent/app/services/mysql_ops.py:64–98`, aynı major veya major+1'i koşulsuz kabul ediyor. Fonksiyonun kendi açıklaması 8.0 → 8.4 → 9.x gereğini söylüyor fakat kodda minor/LTS kontrolü yok. Gerçek saf fonksiyonu AST ile DB/ajan boot etmeden çalıştırınca `detect_major_skip('8.0.40','9.0.1')` sonucu **None** (ret yok). MySQL'in desteklenen upgrade tablosu LTS/Bugfix serisinin atlanamayacağını belirtir. [MySQL upgrade yolları](https://dev.mysql.com/doc/refman/9.7/en/upgrade-paths.html)

**Düzeltme/kabul:**
- [ ] **BUG-28.01** Yalnız ilk sürüm numarasını kıyaslayan kuralı, desteklenen kaynak seri → hedef seri → yöntem matrisiyle değiştirin; LTS/Innovation ayrımını ve gerekli minimum patch'i kapsayın.
- [ ] **BUG-28.02** Image registry'de bulunmayı upgrade uyumluluğu saymayın. Planlama aşamasında doğru MySQL Shell upgrade checker ve migration önkoşullarını çalıştırın; doğrudan atlama yerine gerekli ara sürüm adımlarını kullanıcıya sunun.
- [ ] **BUG-28.03** Prepare ve apply aynı sabitlenmiş sürüm planını ve mevcut gerçek server version'ını yeniden doğrulasın; BUG-11/12 yedek/rollback güvenliği çözülmeden gerçek upgrade çalıştırmayın.
- [ ] **BUG-28.04** 8.0.40 → 9.0.1 doğrudan reddedilmeli; uygun patch önkoşullarıyla 8.0 → 8.4 ve 8.4 → desteklenen hedef kabul edilmeli. Downgrade, bilinmeyen gelecek seri ve hatalı sürüm fail-closed testleri geçmeli.

- [ ] **BUG-28.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-29 — Orta — Update-agent SSE aboneliği bekleme aralığında gelen ilerleme olayını kaybediyor

**Kanıt:** `update-agent/app/services/task_manager.py:100–102` event'i set edip hemen clear ediyor; `:122–127` ilk yield'den sonra `seen=len(updates)` hesaplayıp bekliyor. İlk state cevabı tüketilirken eklenen update, görülmüş gibi işaretleniyor ve uyandırma kayboluyor. İzole gerçek TaskManager testinde initial 0 → update 20 → next beklemesi, pending kayıt olduğu halde timeout verdi. Polling yolu başka controller'da çalışmaya devam edebilir; bu bulgu SSE tüketicisine özgüdür.

**Düzeltme/kabul:**
- [ ] **BUG-29.01** Her abonelik için monoton sequence/cursor kullanın; snapshot ile cursor'ı await/yield öncesi tutarlı alın. Önce pending kayıtları drain edin, sonra koşula bağlı güvenli bekleyin.
- [ ] **BUG-29.02** Event clear zamanlamasını yeni update kaçırmayacak şekilde tasarlayın veya per-subscriber queue/condition kullanın. Reconnect için Last-Event-ID/sequence ve terminal state replay ekleyin; BUG-17 kalıcılığı ile birleştirin.
- [ ] **BUG-29.03** Initial yield sırasında update, iki yield arasında çoklu update, iki subscriber, completed-before-subscribe ve disconnect/reconnect testleri geçsin; olaylar kaybolmasın ve tüketici sonsuza dek asılı kalmasın.

- [ ] **BUG-29.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-30 — Orta — Stateful API logout `TransientToken::delete()` çağırıp oturumu kapatmıyor

**Kanıt:** `P/app/Http/Controllers/Api/V1/AuthController.php:163–177`, refresh tokenları iptal ettikten sonra `currentAccessToken()?->delete()` çağırıyor. Sanctum cookie session'da `Laravel\Sanctum\TransientToken` döndürür; kurulu `P/vendor/laravel/sanctum/src/TransientToken.php` yalnız can/cant içeriyor, delete yok. İzole `method_exists(...,'delete')` false. Null-safe çağrı nesne mevcutken eksik metodu korumaz; ayrıca session invalidate/logout yok.

**Düzeltme/kabul:**
- [ ] **BUG-30.01** Bearer PersonalAccessToken ve cookie/session logout yollarını açıkça ayırın. Bearer'da gerçek tokenı revoke edin; stateful'da ilgili guard logout + session invalidate + CSRF regenerate uygulayın; OTP/lock durumunu temizleyin.
- [ ] **BUG-30.02** Tüm refresh tokenları mı yalnız mevcut oturum ailesi mi kapatılacağına dair mevcut ürün davranışını açık koruyun; UI/API aynı sözleşmeyi kullansın. SEC-05/15/17 ile beraber doğrulayın.
- [ ] **BUG-30.03** Gerçek Sanctum stateful guard fixture'ı ve bearer fixture'ında logout başarılı; aynı cookie/token ile sonraki korunan istek reddediliyor; 500 yok. Yalnız mock PersonalAccessToken kullanan test yeterli değil.

- [ ] **BUG-30.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### BUG-31 — Orta — API pagination negatif/yanlış `per_page` değerini doğrulamıyor

**Kanıt:** `P/app/Http/Controllers/Api/V1/ApiController.php:9–12` integer cast ve `min(...,100)` ile yalnız üst sınır koyuyor. Kurulu Laravel Query Builder `limit` negatif değeri uygulamıyor. DB bağlantısını yasaklayan saf SQL üretim kontrolünde `forPage(1,-1)` sonucu `select * from fixture_rows offset 0`; LIMIT yok. MySQL için bu normal bounded pagination sorgusu değildir ve syntax hatası/500 üretebilir. Başka driver'da sınırsız okuma etkisi ayrıca değişebilir; bu incelemede gerçek DB sorgusu çalıştırılmadı.

**Düzeltme/kabul:**
- [ ] **BUG-31.01** Paylaşılan request kuralında `per_page` için integer min:1 max:100, `page` için pozitif ve ürünce makul sınır uygulayın; array/string/float değerleri sessiz cast ile kabul etmeyin. Belirlenmemiş değer için açık default korunsun.
- [ ] **BUG-31.02** Aynı helper'ı kullanan audit/log/diğer listelerde policy filtrelemesini pagination'dan önce yapın; çok büyük offset gerektiren loglarda cursor pagination kullanın.
- [ ] **BUG-31.03** -1, 0, 1, 100, 101, string/array ve eksik parametre testleri: invalid için 422, valid için en çok izinli sayıda kayıt; her sorgu bounded ve 500'süz olsun.

- [ ] **BUG-31.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### CONFIG-01 — Yüksek — Güvenli ini şablonu mevcut kurulumdaki dosyaya uygulanmıyor

**Kanıt:** Bu çalışma kopyasındaki git dışı `frankenphp/php.ini:318,323` dosyasında `open_basedir` yorum satırı ve `disable_functions` boş. Takip edilen `frankenphp/php.ini.stub:318,325` ise kısıtları içeriyor. `docker-compose.yaml:123` gerçek ini dosyasını mount ediyor. `install.sh:165–178` ve `installer/steps/stubs.py:17–28` yalnız dosya yoksa kopyalıyor; stub düzeltmesi var olan güvenlik ayarını yükseltmiyor. Bu yerel üretilmiş dosya kanıtıdır; üretimdeki effective ini okunmadı.

**Etki:** Güvenlik düzeltmesi deploy edildi sanılırken mevcut siteler eski serbest ayarlarla çalışabilir. Ortak `/var/www/vhosts` open_basedir'i tek başına tenant izolasyonu da sağlamaz; SEC-01/02/09/13'ün etkisini kaldırmaz.

**Düzeltme/kabul:**
- [ ] **CONFIG-01.01** Yönetilen güvenlik direktiflerini kullanıcı performans ayarlarından ayırın; ini tarama/yükleme sırasını dikkate alan sürümlü ve idempotent migration hazırlayın. Mevcut dosyayı tümüyle stub ile ezmeyin.
- [ ] **CONFIG-01.02** Her runtime için effective config'i güvenli bir yerel kontrolle karşılaştırın; drift durumunu panelde görünür yapın. `phpinfo()` çıktısını internetten erişilebilir bırakmayın.
- [ ] **CONFIG-01.03** Önce staging'de desteklenen framework/CLI ihtiyaçlarını ölçün; tenant PHP'ye ayrı güvenlik profili uygulayın, gerekli yönetim komutlarını ayrı servis/kimliğe taşıyın. OS/container sınırını ini ayarının yerine geçirmeyin.
- [ ] **CONFIG-01.04** Eski serbest ini → yeni sürüm geçişinde güvenlik direktifleri etkinleşmeli, meşru kullanıcı tuning'i korunmalı; migration tekrar çalışınca ek değişiklik üretmemeli. Çalışan SAPI değeri ayrıca doğrulansın.

- [ ] **CONFIG-01.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### CONFIG-02 — Yüksek — Upstream desteği bitmiş PHP 8.0/8.1 yeni kurulumda açık geliyor

**Kanıt:** `P/database/seeders/PhpVersionSeeder.php:13–23` PHP 8.0 ve 8.1'i `is_enabled=true`, diğer FPM sürümlerini false oluşturuyor. `php-code-server/Dockerfile:35–60` PHP 7.0–8.5'i aynı image'a kuruyor; installer bunların config'lerini hazırlıyor. 2026-09-06 itibarıyla upstream destekli PHP dalları 8.2–8.5; 7.x/8.0/8.1 bu listede değil. [PHP destek takvimi](https://www.php.net/supported-versions.php)

**Sınır:** Paketler Sury deposundan alınıyor; özel/downstream güvenlik backport sözleşmesi bu incelemede doğrulanmadı. Bu nedenle belirli bir CVE'nin bu image'da kesin bulunduğu veya 7.x sürümlerinin hepsinin aktif olduğu iddia edilmiyor.

**Düzeltme/kabul:**
- [ ] **CONFIG-02.01** Yeni kurulumda upstream destekli, uygulama uyumluluğu doğrulanmış sürümü varsayılan seçin. Seed'in upgrade sırasında mevcut domain sürümünü zorla değiştirmemesini sağlayın.
- [ ] **CONFIG-02.02** Her sürüm için support/EOL metadata ve bağımlı domain envanteri ekleyin. Eski siteye geçiş testleri ve takvim hazırlayın; servisleri habersiz kapatmayın.
- [ ] **CONFIG-02.03** Legacy zorunluluğu varsa süreli istisna ve doğrulanmış downstream patch sorumluluğu tanımlayın; legacy süreçleri düşük yetkili UID ile ve panelin yönetim credential/secret'ları verilmeden çalıştırın. Ortak network ve meşru iç servis bağlantıları korunsun; yönetim API'leri çağıran kimliği ve işlem yetkisini denetlesin. Gerekli olmayan legacy paketlerini normal image'dan ayırın.
- [ ] **CONFIG-02.04** Fresh install destekli sürümü açmalı; upgrade mevcut siteyi bozmayıp EOL durumunu görünür kılmalı; bütün domain türleri için seçili sürümle gerçek smoke test tamamlanmalı.

- [ ] **CONFIG-02.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### CONFIG-03 — Orta — Image build'lerinde değişken uzak içerik ve sabitlenmemiş araç sürümleri var

**Kanıt:** `frankenphp/Dockerfile:104,134` ve `php-code-server/Dockerfile:80,97`, NodeSource setup script'ini doğrudan shell'e veriyor ve global npm araçlarını sürümsüz kuruyor. `php-code-server/Dockerfile:1` base tag'ı digest'siz; `:74–87` Go/code-server indirmeleri sürümlü olsa da hash doğrulaması yok. Aynı commit ile sonraki build farklı executable içerik üretebilir. Bu bir tekrar üretilebilirlik/tedarik zinciri kontrol açığıdır; upstream paketin ele geçirildiği iddiası değildir.

**Düzeltme/kabul:**
- [ ] **CONFIG-03.01** Base image digest, global CLI sürümleri ve indirilen artefact hash/signature bilgilerini tek sürüm envanterinde sabitleyin; script'i doğrudan yürütmek yerine denetlenmiş paket kurulum adımlarını kullanın.
- [ ] **CONFIG-03.02** Sabitleme ile güncelleme politikasını birlikte kurun: otomatik advisory/image scan, gözden geçirilen dependency update ve release SBOM. Laravel/Python/npm lock dosyalarını bu envantere dahil edin.
- [ ] **CONFIG-03.03** Son production image'da gerekli olmayan derleyici/build araçlarını build stage'e ayırın. Kullanıcının amaçlı code-server/CLI özelliğini plansız kaldırmayın; bu araçların tenant execution yetkisini SEC-09'a bağlayın.
- [ ] **CONFIG-03.04** Clean build kurulu sürüm/hash listesini beklenen envanterle eşleştirsin; bozuk hash build'i durdursun. Güncel advisories ayrıca çalıştırılıp sonuç tarihi raporlansın; bu statik incelemeyi “CVE taraması temiz” kanıtı saymayın.

- [ ] **CONFIG-03.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### PERF-01 — Yüksek — Paylaşılan runtime'larda tenant başına kaynak bütçesi yok; FPM havuzları doğrusal büyüyor

**Kanıt:** `docker-compose.resources.yaml:1–8` kaynak limitlerinin varsayılan kapalı ve ayrı COMPOSE_FILE ile isteğe bağlı olduğunu açıkça söylüyor. Etkinleştirilse bile bu dosya servis toplamını sınırlar, servis içindeki tenant'ları birbirinden ayırmaz. `P/app/Services/Domain/PhpFpmConfigRenderer.php:43–46` her pool için dynamic, start_servers=2, max_children=5; `:58–62` varsayılan memory_limit=256M ve execution/input time=3000 saniye üretiyor. Çok sayıda FPM domaini boşta bile süreç sayısını artırır; tek tenant uzun işlerle ortak runtime bütçesini tüketebilir. Ölçülmüş bir bellek/RPS rakamı bu raporda iddia edilmiyor.

**Düzeltme/kabul:**
- [ ] **PERF-01.01** Host kapasitesi ve yönetim servisleri için ayrılmış pay üzerinden CPU/RAM/PID/disk bütçesini çıkarın; resource overlay'in deployment sırasında gerçekten etkin olduğunu inspect ile doğrulayın.
- [ ] **PERF-01.02** Tenant bütçelerini OS/container düzeyinde uygulayın. Ortak FPM kullanılıyorsa pool max_children/toplam çocuk sayısı, idle stratejisi ve request_terminate_timeout'u ölçülen kullanım üzerinden ayarlayın; düşük trafikte ondemand uygunluğunu test edin.
- [ ] **PERF-01.03** Panel ayar validasyonunda tenant'ın sınırsız/hostu aşan memory/time değerleri seçmesini önleyin. Yönetici istisnasını audit edin ve host bütçesiyle denetleyin.
- [ ] **PERF-01.04** Temsilî 10/100 domainli staging yüklerinde idle RSS, peak RSS, p95/p99, queue wait, OOM/PID olaylarını kaydedin. Bir tenant sınırına vardığında panel/DB/diğer tenant'ların hizmeti sürmeli. Kabul eşiğini ölçüm öncesi donanımla birlikte kaydedin.

- [ ] **PERF-01.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### PERF-02 — Yüksek — Uzun işler ortak worker'ları ve HTTP süreçlerini meşgul ediyor; çıktı bütünüyle belleğe alınıyor

**Kanıt:** `alpha-panel/supervisor/AlphaPanel-queue.conf:3–8` aynı varsayılan queue'da üç worker çalıştırıyor. `P/app/Jobs/MonitorUpdateProgressJob.php:26,42–120`, 1800 saniyelik job içinde 3/5 saniye sleep ile işi sonuna kadar takip ediyor. `P/app/Jobs/BackupUploadJob.php:30` timeout=0. `P/app/Http/Controllers/Api/V1/PackageManagerController.php:32–105` 600/1800 saniyelik exec'leri HTTP isteğinde bekliyor; `:173` çıktı kesmesi işlem tamamlandıktan sonra. Ortak `P/app/Services/Portainer/PortainerExecClient.php:98` output'u `getContents()` ile bütünüyle belleğe alıyor. Böylece çıktı limitinin bellek tüketimini sınırladığı varsayımı yanlış.

**Düzeltme/kabul:**
- [ ] **PERF-02.01** Backup/build/update ve kısa yönetim/bildirim işlerini ayrı queue ve ayrılmış worker bütçelerine taşıyın; kritik kısa işler uzun işlerden bağımsız ilerlesin. Sadece worker sayısını artırmak çözüm sayılmasın.
- [ ] **PERF-02.02** Update polling job'u tek kısa sorgu + gecikmeli yeniden planlama biçimine dönüştürün; operation ID bazında tek izleyici ve restart reconciliation sağlayın.
- [ ] **PERF-02.03** Package/build işlemleri yetki+tenant kontrolünden sonra 202 + job ID döndürsün; UI/API bounded progress/output üzerinden izlesin. Timeout, cancel ve yeniden bağlantı davranışlarını tanımlayın.
- [ ] **PERF-02.04** Docker multiplex stdout/stderr verisini akış halinde ayrıştırın; toplam byte/retention sınırı, disk kotası ve truncation işareti ekleyin. Limit aşımında stream'i bırakmak ile process'i iptal etmeyi ayrı ve açık ele alın; secret redaction SEC-18 ile ortak olsun.
- [ ] **PERF-02.05** Her queue için timeout/retry_after/lock TTL ilişkisinin etkin config'ini doğrulayın. Örnek env'de `DB_QUEUE_RETRY_AFTER=3600`, `REDIS_QUEUE_RETRY_AFTER=10800` zaten var; bunları yok sayıp tüm kurulumlarda 90 saniye olduğunu iddia etmeyin. Timeout=0 gibi sınırsız job'u herhangi bir sonlu retry_after'ın güvenli kıldığını da varsaymayın.
- [ ] **PERF-02.06** Staging'de üç uzun sahte iş sürerken kısa webhook/yönetim işi için belirlenen gecikme hedefi korunsun. Büyük sentetik output sabit bellek bütçesiyle işlensin; aynı operation retry'da ikinci gerçek işi başlatmasın.

- [ ] **PERF-02.07** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### PERF-03 — Yüksek — ZIP açma işleminde genişleme, dosya sayısı ve bellek sınırı yok

**Kanıt:** `P/scripts/fm-worker.php:385–430` her entry'yi `getFromIndex()` ile tamamen belleğe alıp diske yazıyor. Entry sayısı, tek/toplam uncompressed byte, compression ratio, disk kotası ve süre bütçesi yok. Bazı okunamayan/atlanmış entry'lere rağmen exit 0 verilebiliyor. Dosya yolu kontrolleri ve düşük yetkili kullanıcıyla çalıştırma, paylaşılan disk/bellek tüketimi sorununu ortadan kaldırmaz.

**Düzeltme/kabul:**
- [ ] **PERF-03.01** ZIP metadata preflight'ında dosya sayısı, tek/toplam açılmış boyut ve riskli oran için ürün limitleri tanımlayın; metadata'ya güvenmekle yetinmeyip stream sırasında gerçek byte sayısını da kesin.
- [ ] **PERF-03.02** Entry'leri sınırlı buffer ile stream edin; tenant boş alan/kota, maksimum süre ve cancellation kontrolleri uygulayın. SEC-12 yol/symlink güvenliğini aynı worker'da koruyun.
- [ ] **PERF-03.03** İzole staging dizinine çıkarın, başarılı ve tam manifest doğrulamasından sonra hedefe kontrollü taşıyın. Kısmi arşiv/bozuk entry'yi sessiz başarı yapmayın; mevcut dosyaları koruyan çakışma politikasını belirleyin.
- [ ] **PERF-03.04** Küçük güvenli fixture'larla normal ZIP, limitin hemen üstü, çok entry, bozuk entry ve iptal testleri yapın. Gerçek zip bomb veya üretim diski doldurma testi gerekmiyor; process RSS ve yazılan toplam byte limit içinde kalmalı.

- [ ] **PERF-03.05** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### PERF-04 — Orta — SSL listesi model serialization sırasında N+1 domain sorgusu üretiyor

**Kanıt:** `P/app/Models/SslCertificate.php:12–18,68–71` append edilen `is_active`, her certificate için `$this->domain` lazy relation'ına erişiyor. `P/app/Http/Controllers/Api/V1/SslController.php:16–22` domainin sertifikalarını `get()` ile döndürüyor; `P/app/Models/Domain.php:188–190` ilişki parent hydration/chaperone tanımlamıyor. Parent query nesnesi zaten elde olmasına rağmen her satır domaini tekrar yükleyebilir. `has_certificate` accessor'ı da listedeki encrypted sertifika içeriğini varlık kontrolü için çözmeye çalışıyor; büyük listeler gereksiz kriptografi/bellek işi yapar.

**Düzeltme/kabul:**
- [ ] **PERF-04.01** Listeyi açık API Resource üzerinden üretin; bilinen domain/active ID'yi kullanın veya relation'ı bir defada hydrate/eager-load edin. Model serialization gizli sorgu başlatmasın.
- [ ] **PERF-04.02** Sertifika içeriği gerekmeyen listede varlık bilgisini içerik decrypt etmeden üretin; private key/certificate gizliliğini koruyun. Listeyi bounded pagination ile sunun.
- [ ] **PERF-04.03** İzole DB'de 1 ve 50 sertifika için sorgu sayısını karşılaştırın: domain sorgu sayısı N ile artmamalı, response'ta PEM olmamalı. Active sertifika ve null içerik doğru kalmalı.

- [ ] **PERF-04.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### QA-01 — Yüksek — Test altyapısı veri silme riskini tam kapatmıyor ve eski sınıflara bağlı test içeriyor

**Kanıt:** `P/phpunit.xml:26–39` ayrı `alphapanel_testing` adını tanımlıyor; bu olumlu önlem mevcut. Ancak env değerlerinde force yok, host/kullanıcı ayrı güvenli hesap olarak sabitlenmiyor; dış ortam/config cache yanlış bağlantıyı dayatabilir. `P/tests/TestCase.php:9–14` bağlantıyı doğrulayan başlangıç engeli içermiyor. `installer/steps/mysql_setup.py:55,66–68`, test schema'sını production panelin `*.* WITH GRANT OPTION` hesabıyla kullanmayı öngörüyor. Yanlış hedefte DB yetkileri koruma sağlamıyor.

**Somut tehlikeli testler:** Aşağıdaki dokuz feature test `RefreshDatabase` kullanıyor: `P/tests/Feature/Api/{ApiTokenIpMiddlewareTest,ApiTokenIpRuleTest,ApiTokenTest,DomainApiTest,PingTest,SslApiTest,WebhookEndpointTest}.php`, `P/tests/Feature/Console/IssueInstallerCertCommandTest.php`, `P/tests/Feature/ImpersonationTest.php`. Ek olarak `P/tests/Unit/DomainAutoApplyServiceTest.php:17–27` DB'ye bağlanıp doğrudan taze migration ile mevcut tabloları silen komutu çağırıyor; `:5–9` import edilen `ApplyRunStatus`, `ApplyChangesService`, `DomainAutoApplyService` sınıfları mevcut app kaynaklarında bulunmuyor. “Unit” adlı paketi çalıştırmak da DB'siz/güvenli sayılmaz. Kök CLAUDE.md zaten veri silen test trait/komutlarını yasaklıyor.

**Düzeltme/kabul:**
- [ ] **QA-01.01** Tam test suite'ini çalıştırmadan önce bu dosyalardan yasaklı veri silme lifecycle'ını kaldırın. Mevcut güvenli test/fixture kurallarına uygun transaction ve yalnız teste ait kayıtları temizleme stratejisi uygulayın; üretim DB'sini sıfırlayan bir komut önermeyin.
- [ ] **QA-01.02** Test için ayrı host/container ve yalnız test schema'sına yetkili kullanıcı oluşturun; production kimlik bilgilerini test runner'a vermeyin. Sadece DB adının sonuna `_testing` eklenmesini yeterli saymayın.
- [ ] **QA-01.03** DB'ye yazma/uygulama boot yan etkilerinden önce fail-closed environment/connection kontrolü koyun: beklenen APP_ENV, host, schema, user ve config cache tutarlılığı. Özel test bootstrap'ı kullanın; yanlış hedefte tek SQL mutation bile yapılmasın.
- [ ] **QA-01.04** Eski DomainAutoApply testinin iş gereksinimini mevcut apply akışına taşıyın; sadece kırmızı testi silerek kapsamı kaybetmeyin. DB gerektirmeyen unit testleri saf PHPUnit sınıfına ayırın.
- [ ] **QA-01.05** Hatalı dış DB_DATABASE/DB_HOST, production credential ve cached config senaryolarını fake connection ile test edin: runner açık hata ile durmalı. Sonra yalnız izole schema üzerinde feature/regression suite'i çalıştırın ve sonuçları kaydedin.

- [ ] **QA-01.06** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

### QA-02 — Orta — Vue/TypeScript kontrolü mevcut kodda altı hata veriyor; build script'i bunu kapsamıyor

**Kanıt:** `P/` içinde kurulu bağımlılıklarla `node node_modules/vue-tsc/bin/vue-tsc.js --noEmit --pretty false` çalıştırıldı; başarısız çıkış ve aşağıdaki **6 hata** alındı. `P/package.json:5–8` yalnız `vite build` ve `vite` script'lerini içeriyor; başarılı Vite transpilation bu typecheck hatalarını temizlemiş sayılmaz.

| Dosya/satır | Hata | Beklenen düzeltme |
|---|---|---|
| `P/resources/js/Components/Layout/AppSidebar.vue:472,480` | `SidebarSubItem` tipinde `external` yok (2 hata) | Mevcut link varyantlarını doğru discriminated/opsiyonel alanlarla tanımlayın; internal/external render davranışını koruyun. |
| Aynı dosya `:475` | Links tipinde `pgadmin` alanı yok | Shared backend link sözleşmesi ve TypeScript interface'i birlikte düzeltin; boş/kapalı servis durumunu ele alın. |
| `resources/js/Composables/useFileManager.ts:133` | Native `ProgressEvent` callback'i AxiosProgressEvent ile uyumsuz | Axios'un gerçek event tipini kullanın; total/loaded belirsizliğini ve iptali işleyin. |
| `P/resources/js/Pages/Auth/Login.vue:141,142` | Dinamik honeypot anahtarı strongly typed form'a doğrudan indexleniyor (2 hata) | Dinamik payload alanlarını açık tip/transform ile üretin; honeypot güvenlik işlevini ve validation errors eşleşmesini koruyun. |

**Düzeltme/kabul:**
- [ ] **QA-02.01** Tablo satırlarının tamamını düzeltin; `as any`, `@ts-ignore` veya typecheck'i kapatma ile geçiştirmeyin.
- [ ] **QA-02.02** `typecheck` script'ini ve release/CI quality gate'ini ekleyin; lint/typecheck/build ayrımını netleştirin. Ortamda CI tanımı ayrıca kurulacaksa güvenli QA-01 test bootstrap'ı ile birlikte yapın.
- [ ] **QA-02.03** Aynı `vue-tsc --noEmit` kontrolü sıfır hata, Vite build başarılı olmalı; sidebar external/pgAdmin bağlantısı, upload progress ve honeypot login akışları smoke testten geçmeli.

- [ ] **QA-02.04** Bu bulgunun bütün kabul koşulları doğrulandı; olumlu/olumsuz testler ve gerekiyorsa mevcut kurulum geçişi, Bölüm 7 formatında kanıtlandı.

## 4. Düzeltme sırası ve bağımlılıklar

Dalga sırası aynı zamanda risk azaltma sırasıdır. Bir dalganın içindeki bağımsız işler paralel geliştirilebilir. Her dalga için ayrı review ve izole doğrulama yapın; büyük ve doğrulanamayan tek bir değişiklik setine dönüştürmeyin.

| Dalga | Bulgular | Bir sonraki aşamaya geçiş koşulu |
|---|---|---|
| 0 — Güvenli çalışma ve veri koruması | QA-01; BUG-11, BUG-12, BUG-14, BUG-17, BUG-28 | Testler üretime yazamıyor. Güvenilir restore kanıtı ve operation lock oluşana kadar yıkıcı upgrade/rollback/cleanup yolu çalıştırılmıyor. |
| 1 — Panel ve yönetim işlemlerinde yetki sınırları | SEC-04, SEC-05, SEC-06, SEC-09, SEC-10, SEC-11, SEC-12, SEC-01, SEC-02, SEC-03, SEC-13, SEC-14, SEC-20; CONFIG-01 | Panelin kullanıcı/token/kaynak kontrolleri geçiyor; tenant komutu root çalışmıyor ve panel sırlarını almıyor. Bütün container'lar aynı networkte iletişim kurarken yetkisiz yönetim işlemi reddediliyor; installer kapanıyor. |
| 2 — Kimlik, token ve entegrasyonlar | SEC-07, SEC-08, SEC-15, SEC-16, SEC-17, SEC-18, SEC-19; BUG-07, BUG-08, BUG-09, BUG-20, BUG-21, BUG-30 | Web/API/passkey/OTP/lock/logout/IP politikası tutarlı; dar token genişleyemiyor; webhook yetkili hedefe güvenli retry ile gidiyor; secrets log/cache'e düşmüyor. |
| 3 — API sözleşmesi ve runtime ayarları | BUG-01, BUG-02, BUG-03, BUG-04, BUG-05, BUG-06, BUG-18, BUG-19, BUG-22, BUG-23, BUG-27, BUG-31 | Eksik route/metot tablolarındaki her satır testli; CRUD başarısı DB/disk/runtime durumuna karşılık geliyor; invalid config çalışan hizmeti bozmuyor. Dalga 1 kontrolleri korunuyor. |
| 4 — İş güvenilirliği ve mevcut kurulum geçişi | BUG-10, BUG-13, BUG-15, BUG-16, BUG-24, BUG-25, BUG-26, BUG-29; CONFIG-02, CONFIG-03 | Backup manifest/retention/cancel ve güncelleme aşamaları güvenilir; eski kurulum migration'ı ve gerçek rollback provası tamam; sürüm/artefact envanteri sabit. |
| 5 — Performans ve kalite kapıları | PERF-01, PERF-02, PERF-03, PERF-04; QA-02 | Kaynak/queue/output bütçeleri ölçümle doğrulanmış; N+1 yok; typecheck/build ve güvenli regression suite geçiyor. |

**Özel bağımlılıklar:** BUG-02/03'ü çalışır hale getirmeden SEC-04/09; webhook wildcard/retry onarımından önce SEC-07/08; config reload onarımında SEC-02/10/11 ve BUG-19; MySQL upgrade API onarımında BUG-11/12/17/28; yedek retention/pagination onarımında BUG-13/14 birlikte kapanmalıdır. API URL kararı BUG-01'de verildikten sonra tüm kabul testleri aynı canonical URL'yi kullanmalı; örnek testlerde eski `/api/v1` varsayımıyla hayalî geçiş kaydı üretmeyin.

## 5. Uygulanacak ortak test ve dağıtım matrisi

Bu tablo yeni bir belirsiz bulgu listesi değildir; yukarıdaki bulguların kapandığını kanıtlamak için ortak kabul kapsamıdır. Testler QA-01 güvenli ortamı kurulduktan sonra yazılmalı/çalıştırılmalıdır.

| Alan | Zorunlu senaryolar | Kayıt edilecek kanıt |
|---|---|---|
| Yetkilendirme | Admin; hiçbir role sahip olmayan kullanıcı; A domain sahibi; B domain sahibi; read-only kullanıcı; dar scope admin tokenı; expired/revoked token | Her route/action için 2xx/403/404 matrisi, response alanları ve side-effect sayısı. Denied istekte Docker/DB mutation çağrısı sıfır. |
| Oturum | Web parola, pending OTP, doğrulanmış OTP, passkey, kilitli ekran, impersonation süresi dolmuş, bearer; logout sonrası replay | Gerçek guard/middleware üzerinden olumlu ve olumsuz test; yalnız controller method mock'u yeterli değil. |
| Tenant ve servis yetkisi | FTP kullanıcılı domain; parent FTP kullanan subdomain; FTP kimliği bulunamayan domain; farklı runtime türleri; symlink; aynı networkte kimliksiz servis isteği | Doğru effective UID/root/container; kimlik yokken işlem reddi; B sentinel dosyası/sırrı A'dan okunamıyor. TCP bağlantısı mümkünken yetkisiz config/exec/read işlemi reddediliyor; yetkili container iletişimi sürüyor. |
| Yarış ve retry | Aynı idempotency key/body ve farklı body/token; eşzamanlı refresh/code exchange; backup/update/cleanup overlap; worker/agent restart | Tek gerçek side-effect; tutarlı operation/delivery ID; tamamlanabilir terminal durum; geçersiz replay reddi. |
| Dış servis hatası | Docker timeout/nonzero exit, config syntax hatası, Drive 429/5xx/yarım stream, webhook retry, disk dolu, backup parçası eksik | Yanlış success/completed yok; eski sağlıklı config/veri korunuyor; kullanıcı hata alıyor; bounded retry. |
| Veri geçişi | Fresh install ve mevcut kurulum; git dışı eski ini; gerçek host path `/opt/...`; UID/group ve daraltılan DB grant geçişi | Sürümlü idempotent migration, önce/sonra ayar farkı, health sonucu, geri dönüş sınırı. |
| Kapasite | Çok domain, uzun build, büyük fakat güvenli output/archive fixture, çok Drive sayfası, 1/50 SSL kaydı | CPU/RAM/PID/disk/queue wait ve p95/p99 ölçümleri; sınır dışı işi keserken kontrol paneli hizmetinin sürmesi. |
| Sır yönetimi | Sahte private_key/FTP password/webhook secret/CAPTCHA secret/refresh token/environment sentinel'leri | Response, Telescope, Laravel log, queue payload, command log ve cache taramasında yetkisiz düz secret yok. |

Dağıtımda bütün container'ların ortak network üyeliğini, DNS/servis keşfini ve gerekli iletişimini koruyun. Panel ve yetkili servislerin yeni kimlik/yetki akışı ile health kontrolleri geçtikten sonra eski credential'ları iptal edin; kimliksiz alternatif yönetim yolu ile kontrolün atlanamadığını doğrulayın. Ağ üyeliği veya genel servis bağlantısını değiştiren bir geçiş uygulamayın. Anahtar/parola rotasyonu yapılacaksa bütün tüketicilerin geçişini birlikte kaydedin; APP_KEY'i plansız değiştirmek encrypted DB alanlarını okunamaz yapabilir. Önce encryption key geçiş/re-encryption tasarımını doğrulayın. Başarılı commit/build, güvenli veri restore'unun yerine geçmez.

## 6. Bu incelemede gerçekten yapılan doğrulamalar

| Kontrol | Gerçek sonuç | Sınır |
|---|---|---|
| PHP syntax lint: `P/app`, `routes`, `config`, `database`, `scripts` altındaki PHP kaynakları | 555 dosya, 0 syntax hatası; sonradan değişen 3 PHP dosyası ayrıca yeniden kontrol edildi ve geçti | İş mantığı, authorization ve dependency metot uyumunu kanıtlamaz. |
| Vue/TS `vue-tsc --noEmit --pretty false` | 6 type hatası, başarısız çıkış; son frontend değişikliği sonrası tekrarda aynı 6 hata; QA-02'de tek tek listelendi | Vite build çalıştırılmadı; frontend uçtan uca testi yapılmadı. |
| API route → controller method karşılaştırması | BUG-02'de 7 eksik route metodu | Laravel app/DB boot etmeden kaynak taraması. |
| API → uygulama servis metodu karşılaştırması | BUG-03'te 17 eksik service çağrı noktası | Signature/return uyuşmazlıkları BUG-04'te ayrıca. |
| Gerçek `IdempotencyKey` middleware, array cache, sahte kullanıcı ve sentinel response | Aynı user/key ile farklı path/method isteği önceki 201 cevabını aldı; ikinci callback çağrısı 0 | Laravel uygulaması/DB boot edilmedi; bellek içi Container/Cache facade kullanıldı. |
| Kurulu Carbon ile 365 gün sonrası farkı | -365; mevcut `<=30` koşulu true | Saf tarih testi; gerçek sertifika değiştirilmedi. |
| Gerçek `SafeCaddyDirectives(strict:false)` kuralı | Sahte başka tenant root + file_server içeriği reddedilmedi | Caddy'ye config uygulanmadı; dosya okunmadı. |
| Gerçek installer IPv6 formatter | `2001:db8::1` → `2001:db8::1/32` | Saf fonksiyon AST ile alındı; kurulum başlatılmadı. |
| Gerçek MySQL upgrade yol validator'ı | `8.0.40 → 9.0.1` için None, yani ret yok | Saf fonksiyon; MySQL/image/registry çağrısı yok. |
| Gerçek TaskManager ile async SSE aralığı | İlk snapshot'tan sonra gelen %20 update'i bekleyen next tüketicisi timeout verdi | Yalnız process belleğinde sahte operation. |
| Sanctum TransientToken metot kontrolü | delete metodu yok | Logout ile gerçek hesap/refresh token değiştirilmedi. |
| Laravel MySQL Query Builder negatif pagination | LIMIT içermeyen `select * from fixture_rows offset 0` üretildi | Connection resolver DB erişiminde exception verecek şekilde ayarlandı; SQL çalıştırılmadı. |

**Çalıştırılmayanlar:** Tam PHPUnit/Artisan suite'i (QA-01), üretim login/API/istismar testleri, Docker/container işlemleri, migration/restore/installer reset, gerçek e-posta/webhook gönderimi, internetten canlı hedefe tarama, yük testi, image/CVE taraması ve Composer/npm/Python advisory audit. Composer CLI bu Windows PATH'inde bulunmadı; paketlere düzeltme/kurulum yapılmadı. Bağımlılık incelemesi manifest/build tarifi ve belirli resmi davranış belgeleriyle sınırlıdır. Bu liste, bunların başarılı olduğu şeklinde sunulmamalıdır.

**Sürüm doğrulamasında başvurulan birincil belgeler:** İlgili iddianın yanında Docker, Caddy, Laravel/Sanctum/Telescope, MySQL, PHP, Python ve Google Drive belgelerinin doğrudan bağlantıları bulunur. Web'deki genel davranış bilgisi ile bu depodaki somut kod kanıtı ayrı değerlendirilmiştir.

## 7. Claude için tamamlanma kayıt şablonu

Her ID için aşağıdaki kaydı doldurun. İlgili bulgunun altındaki bütün numaralı alt kutular ve tablolardaki satırlar tamamlanmadan bulguyu kapatmayın. Kodun başka bir çalışma tarafından zaten düzeltilmiş olması halinde de mevcut kod/satır ve regression test kanıtı ekleyin; “muhtemelen çözülmüş” yeterli değildir.

```text
Bulgu ID:
Ortak network ve gerekli servis iletişimi korunuyor mu:
Durum: Açık / Çalışılıyor / Doğrulandı / Gerekçeli engel
Kök neden ve yapılan değişiklik:
Değişen dosyalar:
Tamamlanan alt görev ID'leri:
Olumlu testler ve sonuçları:
Olumsuz/yetkisiz/yarış/hata testleri ve sonuçları:
Fresh install doğrulaması:
Mevcut kurulum migration ve doğrulaması:
Secret rotasyonu (gerekiyorsa; secret değerini yazma):
Dağıtım ve sağlık kontrolü:
Geri dönüş planı / veri uyumluluğu sınırı:
Kanıt konumu (test dosyası, güvenli log, commit/PR):
Kalan engel / ürün kararı:
```

Uygulanmayan maddeyi veya başarısız testi kapanmış gibi işaretlemeyin. Nihai uygulama tesliminde toplam ID sayısını, kapananları ve gerekçeli açık kalanları ayrı sayın; çizelgede boş/açıklamasız satır bırakmayın. Bu audit dosyası üretildiğinde hiçbir düzeltme tamamlandı sayılmamaktadır.

## 8. Eksiksizlik çizelgesi

<!-- AUDIT_TRACKER_START -->
**Toplam: 60 bulgu; 9 kritik, 34 yüksek, 17 orta. Numaralı alt görev/kapanış kontrolü: 291.**

| ID | Öncelik / konu | Alt görev sayısı | Kod + test + geçiş kanıtı |
|---|---|---:|---|
| SEC-01 | Kritik — Docker yönetim API'sinde çağıran servis kimliği ve işlem yetkisi eksik | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-02 | Kritik — Caddy yönetim API'si hosted kodla aynı erişim sınırında | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-03 | Kritik — Root kurucu HTTP arayüzü kimlik doğrulamasız sır ve yıkıcı işlem sunuyor | 6 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-04 | Kritik — API ability kontrolü kullanıcı/tenant yetkilendirmesinin yerine kullanılıyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-05 | Kritik — Web oturumunun OTP aşaması API üzerinden atlanabiliyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-06 | Yüksek — Dar kapsamlı admin tokenı sınırsız token üretebiliyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-07 | Yüksek — Handshake global webhook kaydını herhangi bir hesapla değiştirebiliyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-08 | Yüksek — Idempotency cache yetki sınırlarını ve işlem kimliğini karıştırıyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-09 | Kritik — Tenant uygulama kodu bazı yönetim akışlarında root çalışıyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-10 | Kritik — Custom Caddy bypass tenant'ın dosya kökünü ve upstream sınırını kaldırıyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-11 | Yüksek — PHP-FPM ayarına satır enjekte edilerek sabit güvenlik ayarı değiştirilebiliyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-12 | Yüksek — ZIP oluşturma iç symlink'lerde domain dizini kontrolünü atlıyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-13 | Yüksek — Global Cloudflare tokenı hosted PHP environment'ında | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-14 | Yüksek — phpMyAdmin ve FTP için gereğinden geniş panel veritabanı/sır erişimi | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-15 | Yüksek — İkinci faktör için üç ayrı durum alanı tutarsız kullanılıyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-16 | Yüksek — Login IP politikası alternatif giriş yollarında uygulanmıyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-17 | Yüksek — Refresh token ve OAuth code tüketimi atomik değil | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-18 | Yüksek — Özel secret alanları ve komut satırları loglara sızabiliyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-19 | Orta — Giriş yöntemi uçları hesap varlığını ve bazı e-postaları açıklıyor | 3 | [ ] Açık; Bölüm 7 kaydı gerekli |
| SEC-20 | Yüksek — Kurucu tek IPv6 yönetici IP'sini geniş `/32` ağına dönüştürüyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-01 | Yüksek — Belgelenen `/api/v1` prefix'i çalıştırılan route'larda yok | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-02 | Yüksek — Yedi route olmayan controller action'ına bağlı | 8 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-03 | Yüksek — API controller'ları mevcut olmayan servis metotlarını çağırıyor | 18 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-04 | Yüksek — SSL ve yedek indirme API'lerinin argüman/alan sözleşmesi yanlış | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-05 | Yüksek — PHP ayarı kaydı ile çalışan PHP/FPM durumu birbirinden kopuyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-06 | Yüksek — API ayar alanları model şemasıyla uyuşmuyor; başarı cevabı etkisiz kalıyor | 9 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-07 | Orta — Handshake wildcard webhook hiçbir normal olaya abone olmuyor | 3 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-08 | Orta — Webhook retry ayarları kullanılmıyor; delivery kimliği de retry'da değişiyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-09 | Orta — Tek webhook'u test etme işlemi bütün abonelere test verisi gönderiyor | 3 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-10 | Yüksek — Silinen FTP kullanıcısı users.env içinde korunup yeniden oluşturuluyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-11 | Kritik — MySQL upgrade yedeği çalışan data dizininin tutarsız fiziksel kopyası | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-12 | Kritik — MySQL recovery mevcut veriyi doğrulanmış alternatif olmadan kaldırıyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-13 | Yüksek — Laravel backup kısmi/tam başarısızlığı “completed” sayıp eski yedekleri temizleyebiliyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-14 | Yüksek — Backup cancel/restart ve overlap kilidi işleri kaybediyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-15 | Yüksek — Update-agent Compose bind kaynaklarını host'ta yanlış `/project` yoluna çözüyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-16 | Yüksek — Güncelleme kritik adım hatalarını başarı olarak sonlandırıyor ve hedef sürümü sabitlemiyor | 6 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-17 | Yüksek — Update/prepare/apply/rollback/cleanup arasında ortak kilit ve kalıcı durum yok | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-18 | Orta — MySQL API prepare her zaman geçersiz `latest` sürümünü gönderiyor | 3 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-19 | Yüksek — Hatalı custom config reload'u bütün web container'ını durdurabilecek restart'a dönüşüyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-20 | Orta — Parola sıfırlama ve TOTP arayüz yolları eksik | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-21 | Orta — DNS/ACME ayar permission adları izin kataloğunda yok | 3 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-22 | Orta — Bir yıl sonra bitecek sertifika “yakında doluyor” olarak işaretleniyor | 3 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-23 | Yüksek — API PHP sürümü açma/kapatma işlemi çalışma zamanını değiştirmiyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-24 | Orta — Cron işi worker zaman aşımıyla yarıda kalıp logu `running` bırakabiliyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-25 | Orta — Google Drive listeleri ve retention yalnız ilk sayfayı işliyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-26 | Orta — “Her iki haftada bir” ve gün aralıkları takvim sınırında yanlış | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-27 | Yüksek — MySQL config kaydı Linux dosya izinlerini değiştirip ayarı okunamaz bırakıyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-28 | Yüksek — MySQL upgrade yolu yalnız major sayısına bakıyor; zorunlu LTS basamağı atlanabiliyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-29 | Orta — Update-agent SSE aboneliği bekleme aralığında gelen ilerleme olayını kaybediyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-30 | Orta — Stateful API logout `TransientToken::delete()` çağırıp oturumu kapatmıyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| BUG-31 | Orta — API pagination negatif/yanlış `per_page` değerini doğrulamıyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| CONFIG-01 | Yüksek — Güvenli ini şablonu mevcut kurulumdaki dosyaya uygulanmıyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| CONFIG-02 | Yüksek — Upstream desteği bitmiş PHP 8.0/8.1 yeni kurulumda açık geliyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| CONFIG-03 | Orta — Image build'lerinde değişken uzak içerik ve sabitlenmemiş araç sürümleri var | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| PERF-01 | Yüksek — Paylaşılan runtime'larda tenant başına kaynak bütçesi yok; FPM havuzları doğrusal büyüyor | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| PERF-02 | Yüksek — Uzun işler ortak worker'ları ve HTTP süreçlerini meşgul ediyor; çıktı bütünüyle belleğe alınıyor | 7 | [ ] Açık; Bölüm 7 kaydı gerekli |
| PERF-03 | Yüksek — ZIP açma işleminde genişleme, dosya sayısı ve bellek sınırı yok | 5 | [ ] Açık; Bölüm 7 kaydı gerekli |
| PERF-04 | Orta — SSL listesi model serialization sırasında N+1 domain sorgusu üretiyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
| QA-01 | Yüksek — Test altyapısı veri silme riskini tam kapatmıyor ve eski sınıflara bağlı test içeriyor | 6 | [ ] Açık; Bölüm 7 kaydı gerekli |
| QA-02 | Orta — Vue/TypeScript kontrolü mevcut kodda altı hata veriyor; build script'i bunu kapsamıyor | 4 | [ ] Açık; Bölüm 7 kaydı gerekli |
<!-- AUDIT_TRACKER_END -->

