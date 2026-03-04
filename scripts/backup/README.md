# Backup Scripts

Bu dizin, sunucunun otomatik yedekleme scriptlerini içerir. Yedek dosyaların kendisi burada **tutulmaz**; scriptler yedekleri harici bir depolama alanına (Google Drive) gönderir.

## Backup Akışı

```
backup.sh
 ├── web_backup.sh            → vhosts dizinlerini arşivler
 ├── mysql_backup.sh          → MySQL veritabanlarını dump'lar
 └── mongodb_backup.sh        → MongoDB veritabanlarını dump'lar

drivebackup.py               → arşivleri Google Drive'a yükler
```

## Manuel Oluşturulması Gereken Dosyalar

Aşağıdaki dosyalar hassas bilgi içerdiğinden git'e eklenmez. Sistemi kurduktan sonra bunları **elle oluşturmanız** gerekir.

---

### 1. `server_info.sh`

Sunucu yolları ve veritabanı bağlantı bilgilerini içerir.

```bash
cp server_info.sh.example server_info.sh
# Ardından server_info.sh dosyasını editörde açıp doldurun
```

---

### 2. `settings.json`

Google Drive backup hedefini (klasör ID ve hesap adresi) tanımlar.

```bash
cp settings.json.example settings.json
# drive_folder_id ve google_account_address alanlarını doldurun
```

`drive_folder_id` değerini Google Drive'da hedef klasörü açıp URL'den alabilirsiniz:
`https://drive.google.com/drive/folders/**<FOLDER_ID>**`

---

### 3. `credentials.json`

Google OAuth2 istemci kimlik bilgileri. [Google Cloud Console](https://console.cloud.google.com/) üzerinden:

1. **APIs & Services → Credentials → Create Credentials → OAuth 2.0 Client ID**
2. Application type: **Desktop app**
3. İndirilen JSON dosyasını `credentials.json` olarak bu dizine kopyalayın

---

### 4. `service_account.json` *(opsiyonel)*

Google Drive API için service account anahtarı. [Google Cloud Console](https://console.cloud.google.com/) üzerinden:

1. **APIs & Services → Credentials → Create Credentials → Service Account**
2. Service account oluşturduktan sonra **Keys → Add Key → JSON**
3. İndirilen dosyayı `service_account.json` olarak bu dizine kopyalayın

---

### 5. `token.json`

Bu dosya otomatik oluşturulur. `drivebackup.py` ilk çalıştırıldığında Google OAuth akışını başlatır ve token'ı bu dosyaya kaydeder. Sonraki çalışmalarda otomatik yenilenir (refresh token).

---

## Kurulum

```bash
# Python bağımlılıklarını yükleyin
pip install -r requirements.txt

# Yukarıdaki dosyaları oluşturduktan sonra ilk çalıştırma:
python3 drivebackup.py
# → Tarayıcıda Google OAuth onayı istenir, token.json otomatik oluşturulur
```
