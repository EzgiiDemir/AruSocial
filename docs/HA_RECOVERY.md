# HA / recovery (production)

Bu depo bir canlı küme **çalıştırmaz**. Aşağıdaki plan, `deploy/` içindeki
Compose iskeleti bir sunucuya konduğunda uygulanır.

## Bileşenler

| Bileşen | Varsayılan | Kesinti |
|---|---|---|
| Laravel (PHP-FPM) | `app` servisi, 1 replica | Yeni container |
| nginx + TLS | ters vekil; sertifika host’ta | Sertifika yenileme (certbot) |
| PostgreSQL | tek instance + volume | `deploy/scripts/pg_backup.sh` |
| Reverb | websocket | Yeniden başlat; istemci yeniden bağlanır |
| Kuyruk | `database` + `php artisan queue:work` | Worker restart |
| OSRM | isteğe bağlı `osrm` profili | Boş `ROUTING_BASE_URL` → API 501 |

## Yedek

Günlük (cron):

```bash
./deploy/scripts/pg_backup.sh
```

Çıktı: `deploy/backups/arucad-YYYYMMDD-HHMMSS.sql.gz`
En az 7 gün, tercihen kampüs dışındaki object storage.

Medya: `storage/app/public` volume’unu aynı cron ile rsync/s3.

## Geri yükleme

1. Uygulamayı bakım moduna al (`php artisan down`).
2. `gunzip -c backup.sql.gz | psql -U arucad -d arucad`
3. Medya volume’unu geri kopyala.
4. `php artisan migrate --force` (yedek eskiyse).
5. `php artisan up`

## Yüksek erişilebilirlik (sonraki adım)

Tek VM Compose **HA değildir**. Gerçek HA: yönetilen Postgres
(Primary + replica), en az iki app replica, yük dengeleyici, ayrı
Reverb. Bu hesaplar/sunucular repoda yok.

## RTO / RPO (hedef, henüz ölçülmedi)

- RPO: 24 saat (günlük yedek)
- RTO: yedekten tek VM restore, birkaç saat
